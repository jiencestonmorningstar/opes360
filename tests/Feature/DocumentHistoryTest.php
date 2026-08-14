<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Sales\Index as SalesIndex;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Services\PaymentRecorder;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Finding a document, and reading what happened to it.
 *
 * All three behaviours here were reported as missing by a business whose only
 * documents were quotations: the list opened on an empty Invoices tab, the
 * customer's name was not a link, and nothing on the page said who issued the
 * document or when.
 */
class DocumentHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
        ChartOfAccounts::seed($this->company);
    }

    protected function contact(string $name = 'Boulangerie Nkolbisson'): Contact
    {
        return Contact::create(['type' => 'customer', 'name' => $name]);
    }

    protected function makeDocument(DocumentType $type, float $amount = 50000, bool $issue = true): Document
    {
        $doc = Document::create([
            'type' => $type,
            'contact_id' => $this->contact()->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $amount,
            'tax_total' => 0,
            'total' => $amount,
            'amount_paid' => 0,
            'balance' => $amount,
            'created_by' => $this->owner->id,
        ]);

        DocumentLine::create([
            'document_id' => $doc->id,
            'description' => 'Service',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => $amount,
            'tax_amount' => 0,
            'line_total' => $amount,
            'sort_order' => 0,
        ]);

        return $issue
            ? app(DocumentIssuer::class)->issue($doc->fresh(), $this->owner)
            : $doc->fresh();
    }

    // ── Landing on a tab that has something in it ────────────────────────

    /**
     * The reported bug: a business whose only documents are quotations opened
     * Sales, saw an empty Invoices tab, and concluded its work had not saved.
     */
    public function test_the_list_opens_on_a_type_that_has_documents(): void
    {
        $this->makeDocument(DocumentType::Quotation);

        Livewire::actingAs($this->owner)
            ->test(SalesIndex::class)
            ->assertSet('type', 'quotation');
    }

    public function test_invoices_still_win_when_they_exist(): void
    {
        $this->makeDocument(DocumentType::Quotation);
        $this->makeDocument(DocumentType::Invoice);

        Livewire::actingAs($this->owner)
            ->test(SalesIndex::class)
            ->assertSet('type', 'invoice');
    }

    /** A link that names a type must land on it, empty or not. */
    public function test_an_explicit_type_in_the_url_is_honoured(): void
    {
        $this->makeDocument(DocumentType::Quotation);

        Livewire::withQueryParams(['type' => 'invoice'])
            ->actingAs($this->owner)
            ->test(SalesIndex::class)
            ->assertSet('type', 'invoice');
    }

    public function test_an_empty_business_still_lands_somewhere_sensible(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SalesIndex::class)
            ->assertSet('type', 'invoice');
    }

    // ── Reaching the customer from the list ──────────────────────────────

    public function test_the_customer_name_links_to_their_profile(): void
    {
        $document = $this->makeDocument(DocumentType::Quotation);

        $this->actingAs($this->owner)
            ->get(route('sales', ['type' => 'quotation']))
            ->assertOk()
            ->assertSee(route('customers.show', $document->contact), false);
    }

    // ── History ──────────────────────────────────────────────────────────

    public function test_issuing_is_recorded_in_the_documents_own_trail(): void
    {
        $document = $this->makeDocument(DocumentType::Invoice);

        $this->assertDatabaseHas('document_approvals', [
            'document_id' => $document->id,
            'action' => 'issued',
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_the_history_shows_creation_issue_payment_and_void(): void
    {
        $document = $this->makeDocument(DocumentType::Invoice, 50000);

        app(PaymentRecorder::class)->record(
            document: $document,
            cashier: $this->owner,
            amount: 20000,
            method: PaymentMethod::Cash,
        );

        $this->actingAs($this->owner)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('Created')
            ->assertSee('Issued')
            ->assertSee('Payment received');
    }

    public function test_a_void_and_its_reason_appear_in_the_history(): void
    {
        $document = $this->makeDocument(DocumentType::Quotation);

        app(DocumentConverter::class)->void($document, $this->owner, 'Client changed the scope');

        $this->actingAs($this->owner)
            ->get(route('documents.show', $document->fresh()))
            ->assertOk()
            ->assertSee('Voided')
            ->assertSee('Client changed the scope');
    }

    /** A converted document should say what it came from, and link back. */
    public function test_a_conversion_is_shown_and_links_to_its_source(): void
    {
        $quotation = $this->makeDocument(DocumentType::Quotation, 30000);

        $invoice = app(DocumentConverter::class)->convert($quotation, $this->owner);

        $this->actingAs($this->owner)
            ->get(route('documents.show', $invoice))
            ->assertOk()
            ->assertSee('Converted')
            ->assertSee(route('documents.show', $quotation), false);
    }

    // ── Notes ────────────────────────────────────────────────────────────

    /**
     * Notes used to appear only on the printed copy, so a quotation's scope and
     * payment schedule could be typed and then found nowhere on screen.
     */
    public function test_notes_are_visible_on_the_document_page(): void
    {
        $document = $this->makeDocument(DocumentType::Quotation);

        $document->forceFill(['notes' => "PAYMENT TERMS\n\n60% on acceptance."])->saveQuietly();

        $this->actingAs($this->owner)
            ->get(route('documents.show', $document->fresh()))
            ->assertOk()
            ->assertSee('Notes & terms')
            ->assertSee('60% on acceptance.');
    }

    /**
     * The structure must survive to the printed page rather than collapsing
     * into one block — a heading printed as a heading, a list as a list.
     */
    public function test_the_printed_copy_keeps_the_structure_of_the_notes(): void
    {
        $document = $this->makeDocument(DocumentType::Quotation);

        $document->forceFill([
            'notes' => "VALIDITY\n\n30 days from issue.\n\nINCLUDED\n\n• Cloud hosting\n• Backups",
        ])->saveQuietly();

        $response = $this->actingAs($this->owner)
            ->get(route('documents.print', $document->fresh()))
            ->assertOk()
            ->assertSee('30 days from issue.');

        // A heading rendered as one, and the bullets as real list items.
        $response->assertSee('<p class="notes-h">VALIDITY</p>', false);
        $response->assertSee('<li>Cloud hosting</li>', false);
    }
}
