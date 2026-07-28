<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Documents\Show;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Services\PaymentRecorder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
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

        $this->contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);
    }

    protected function issued(DocumentType $type, float $total = 400): Document
    {
        $document = Document::create([
            'type' => $type,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => $total,
            'total' => $total,
            'balance' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Site works',
            'quantity' => 2,
            'unit_price' => $total / 2,
            'line_total' => $total,
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->user);
    }

    public function test_a_quotation_converts_into_an_issued_invoice(): void
    {
        $quotation = $this->issued(DocumentType::Quotation, 400);
        $year = now()->format('Y');

        $invoice = app(DocumentConverter::class)->convert($quotation, $this->user);

        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame(DocumentStatus::Issued, $invoice->status);
        $this->assertSame("INV-{$year}-00001", $invoice->number);
        $this->assertSame('400.00', $invoice->total);
        $this->assertSame($quotation->id, $invoice->parent_document_id);

        // Lines are copied, so the invoice stands on its own.
        $this->assertCount(1, $invoice->lines);
        $this->assertSame('Site works', $invoice->lines->first()->description);

        // The source is untouched apart from being marked accepted.
        $this->assertSame(DocumentStatus::Accepted, $quotation->fresh()->status);
        $this->assertSame('400.00', $quotation->fresh()->total);
    }

    public function test_a_quotation_cannot_be_converted_twice(): void
    {
        $quotation = $this->issued(DocumentType::Quotation);
        $converter = app(DocumentConverter::class);

        $converter->convert($quotation, $this->user);

        $this->assertFalse($converter->canConvert($quotation->fresh()));

        $this->expectException(RuntimeException::class);
        $converter->convert($quotation->fresh(), $this->user);
    }

    public function test_voiding_revokes_the_token_so_a_scan_reports_voided(): void
    {
        $invoice = $this->issued(DocumentType::Invoice, 400);
        $token = $invoice->verificationToken->token;

        app(DocumentConverter::class)->void($invoice, $this->user, 'Duplicate');

        $this->assertSame(DocumentStatus::Void, $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->balance);
        $this->assertNotNull($invoice->verificationToken->fresh()->revoked_at);

        app(CurrentCompany::class)->set(null);

        $this->get("/v/{$token}")->assertOk()->assertSee('Voided');
    }

    public function test_voiding_is_refused_once_money_has_been_taken(): void
    {
        $invoice = $this->issued(DocumentType::Invoice, 400);

        app(PaymentRecorder::class)->record($invoice, $this->user, 100.0, PaymentMethod::Cash);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payments against it/');

        app(DocumentConverter::class)->void($invoice->fresh(), $this->user);
    }

    public function test_the_document_page_converts_and_voids(): void
    {
        $quotation = $this->issued(DocumentType::Quotation);

        Livewire::actingAs($this->user)
            ->test(Show::class, ['document' => $quotation])
            ->assertSee('Convert to Invoice')
            ->call('convert')
            ->assertRedirect();

        $invoice = Document::query()->invoices()->firstOrFail();

        Livewire::actingAs($this->user)
            ->test(Show::class, ['document' => $invoice])
            ->call('openVoid')
            ->assertSet('voidingOpen', true)
            ->set('voidReason', 'Cancelled order')
            ->call('voidDocument')
            ->assertHasNoErrors()
            ->assertSee('Void');

        $this->assertSame(DocumentStatus::Void, $invoice->fresh()->status);
    }

    public function test_the_void_panel_surfaces_the_paid_guard_in_place(): void
    {
        $invoice = $this->issued(DocumentType::Invoice, 400);
        app(PaymentRecorder::class)->record($invoice, $this->user, 400.0, PaymentMethod::Cash);

        Livewire::actingAs($this->user)
            ->test(Show::class, ['document' => $invoice->fresh()])
            ->call('openVoid')
            ->call('voidDocument')
            ->assertHasErrors(['voidReason']);

        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);
    }

    public function test_the_customer_form_creates_a_contact(): void
    {
        $result = $this->returned(
            Livewire::actingAs($this->user)
                ->test(CustomerForm::class, ['type' => 'customer'])
                ->call('save', [
                    'type' => 'customer',
                    'name' => 'Blue Sky Ltd',
                    'email' => 'HELLO@BlueSky.test',
                    'phone' => '+234 700 000 1111',
                    'payment_terms_days' => '30',
                ])
        );

        $this->assertTrue($result['ok']);

        $contact = Contact::where('name', 'Blue Sky Ltd')->firstOrFail();

        $this->assertSame('customer', $contact->type);
        $this->assertSame('hello@bluesky.test', $contact->email);   // normalised
        $this->assertSame(['+234 700 000 1111'], $contact->phones);
        $this->assertSame(30, $contact->payment_terms_days);
    }

    public function test_the_customer_form_requires_a_name(): void
    {
        $result = $this->returned(
            Livewire::actingAs($this->user)
                ->test(CustomerForm::class)
                ->call('save', ['type' => 'customer', 'email' => 'no-name@example.com'])
        );

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function test_a_contact_created_offline_gets_the_same_shape_as_the_form(): void
    {
        $id = (string) Str::ulid();

        // The offline path must not produce a second-class record: same columns,
        // same normalisation, including the derived tax-ID blind index.
        $this->actingAs($this->user)->postJson('/api/sync/v1/push', [
            'envelopes' => [[
                'id' => (string) Str::ulid(),
                'entity_type' => 'contact',
                'entity_id' => $id,
                'operation' => 'create',
                'payload' => [
                    'type' => 'customer',
                    'name' => 'Roadside Stall',
                    'email' => 'stall@example.test',
                    'phones' => ['+234 800 111 2222'],
                    'address' => ['city' => 'Lagos', 'country' => 'NG'],
                    'tax_id' => 'TIN-99 8877',
                ],
            ]],
        ])->assertOk();

        $contact = Contact::findOrFail($id);

        $this->assertSame('Roadside Stall', $contact->name);
        $this->assertSame(['+234 800 111 2222'], $contact->phones);
        $this->assertSame('Lagos', data_get($contact->address, 'city'));
        $this->assertSame(
            hash('sha256', 'TIN998877'),
            $contact->tax_id_index,
        );
    }

    public function test_a_device_cannot_choose_its_own_blind_index(): void
    {
        $id = (string) Str::ulid();

        $this->actingAs($this->user)->postJson('/api/sync/v1/push', [
            'envelopes' => [[
                'id' => (string) Str::ulid(),
                'entity_type' => 'contact',
                'entity_id' => $id,
                'operation' => 'create',
                'payload' => [
                    'type' => 'customer',
                    'name' => 'Someone',
                    'tax_id' => 'TIN-1234',
                    'tax_id_index' => 'attacker-controlled-value',
                ],
            ]],
        ])->assertOk();

        // The index is derived from the tax ID, never taken from the envelope.
        $this->assertSame(hash('sha256', 'TIN1234'), Contact::findOrFail($id)->tax_id_index);
    }

    public function test_the_offline_shell_page_is_public(): void
    {
        // Precached by the service worker, so it must render without a session.
        $this->get('/offline')->assertOk()->assertSee("You're offline", false);
    }
}
