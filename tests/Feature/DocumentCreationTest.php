<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Documents\Create;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\NumberLease;
use App\Models\User;
use App\Services\DocumentIssuer;
use App\Services\DocumentNumbers;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentCreationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($company, $this->user);
        $this->user->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $this->contact = Contact::create(['name' => 'A Customer']);
    }

    public function test_numbers_are_sequential_within_a_type_and_year(): void
    {
        $numbers = app(DocumentNumbers::class);
        $year = now()->format('Y');

        $this->assertSame("INV-{$year}-00001", $numbers->next(DocumentType::Invoice));
        $this->assertSame("INV-{$year}-00002", $numbers->next(DocumentType::Invoice));
        // A different type runs its own sequence.
        $this->assertSame("QUO-{$year}-00001", $numbers->next(DocumentType::Quotation));
    }

    public function test_an_exhausted_lease_rolls_into_a_new_block_without_overlap(): void
    {
        $numbers = app(DocumentNumbers::class);
        $year = (int) now()->format('Y');

        // A nearly-exhausted server lease with one number left.
        NumberLease::create([
            'document_type' => 'invoice',
            'year' => $year,
            'range_start' => 1,
            'range_end' => 5,
            'next_available' => 5,
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $this->assertSame("INV-{$year}-00005", $numbers->next(DocumentType::Invoice));
        // The next allocation opens a fresh block starting after the old range.
        $this->assertSame("INV-{$year}-00006", $numbers->next(DocumentType::Invoice));

        $this->assertDatabaseHas('number_leases', [
            'document_type' => 'invoice',
            'range_start' => 6,
            'status' => 'active',
        ]);
    }

    public function test_issuing_assigns_number_hash_and_verification_token(): void
    {
        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'subtotal' => 100,
            'total' => 100,
            'balance' => 100,
        ]);

        $document->lines()->create([
            'company_id' => $document->company_id,
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        $issued = app(DocumentIssuer::class)->issue($document, $this->user);

        $this->assertSame(DocumentStatus::Issued, $issued->status);
        $this->assertNotNull($issued->number);
        $this->assertNotNull($issued->content_hash);
        $this->assertNotNull($issued->verification_token_id);
        // The hash must verify against what the database now holds.
        $this->assertFalse($issued->fresh()->load('lines')->isTampered());
    }

    public function test_an_issued_document_cannot_be_issued_twice(): void
    {
        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Issued,
            'number' => 'INV-X-1',
            'issue_date' => now()->toDateString(),
            'total' => 50,
            'balance' => 50,
        ]);

        $this->expectException(\RuntimeException::class);

        app(DocumentIssuer::class)->issue($document, $this->user);
    }

    /** @return array<string, mixed> */
    protected function payload(array $lines, array $overrides = []): array
    {
        return array_merge([
            'contact_id' => $this->contact->id,
            'issue_date' => now()->toDateString(),
            'due_date' => null,
            'notes' => '',
            'lines' => $lines,
        ], $overrides);
    }

    public function test_the_form_saves_a_draft_without_a_number(): void
    {
        $result = $this->returned(Livewire::actingAs($this->user)
            ->test(Create::class, ['type' => 'invoice'])
            ->call('save', $this->payload([
                ['description' => 'Web design', 'quantity' => '2', 'unit_price' => '150'],
            ]), false)
        );

        $this->assertTrue($result['ok']);

        $document = Document::firstOrFail();

        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertNull($document->number);
        $this->assertSame('300.00', $document->total);
        $this->assertCount(1, $document->lines);
    }

    public function test_the_form_issues_an_invoice_with_a_final_number(): void
    {
        $year = now()->format('Y');

        $result = $this->returned(Livewire::actingAs($this->user)
            ->test(Create::class, ['type' => 'invoice'])
            ->call('save', $this->payload([
                ['description' => 'Web design', 'quantity' => '1', 'unit_price' => '500'],
            ]), true)
        );

        $this->assertTrue($result['ok']);

        $document = Document::firstOrFail();

        $this->assertSame(DocumentStatus::Issued, $document->status);
        $this->assertSame("INV-{$year}-00001", $document->number);
        $this->assertNotNull($document->verification_token_id);
        $this->assertFalse($document->load('lines')->isTampered());
    }

    public function test_the_form_rejects_a_missing_customer_and_empty_lines(): void
    {
        $result = $this->returned(Livewire::actingAs($this->user)
            ->test(Create::class, ['type' => 'invoice'])
            ->call('save', $this->payload(
                [['description' => '', 'quantity' => '1', 'unit_price' => '10']],
                ['contact_id' => ''],
            ), true)
        );

        // Errors come back to the client to render, so the same messages appear
        // whether the save happened online or on the device.
        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('contact_id', $result['errors']);
        $this->assertArrayHasKey('lines.0.description', $result['errors']);

        $this->assertSame(0, Document::count());
    }

    public function test_the_form_rejects_a_contact_from_another_company(): void
    {
        $otherOwner = User::factory()->create();
        $other = Company::create(['slug' => 'other', 'name' => 'Other Ltd', 'owner_id' => $otherOwner->id]);

        $foreign = app(CurrentCompany::class)->as(
            $other,
            fn () => Contact::create(['name' => 'Foreign Customer'])
        );

        $result = $this->returned(Livewire::actingAs($this->user)
            ->test(Create::class, ['type' => 'invoice'])
            ->call('save', $this->payload(
                [['description' => 'Work', 'quantity' => '1', 'unit_price' => '10']],
                ['contact_id' => $foreign->id],
            ), false)
        );

        // The tenant scope makes the foreign id simply not exist.
        $this->assertFalse($result['ok']);
        $this->assertSame(0, Document::count());
    }

    public function test_the_customer_search_is_scoped_to_the_company(): void
    {
        $otherOwner = User::factory()->create();
        $other = Company::create(['slug' => 'other', 'name' => 'Other Ltd', 'owner_id' => $otherOwner->id]);

        app(CurrentCompany::class)->as(
            $other,
            fn () => Contact::create(['name' => 'A Customer From Elsewhere'])
        );

        $results = $this->returned(Livewire::actingAs($this->user)
            ->test(Create::class, ['type' => 'invoice'])
            ->call('searchContacts', 'Customer')
        );

        $this->assertCount(1, $results);
        $this->assertSame($this->contact->id, $results[0]['id']);
    }
}
