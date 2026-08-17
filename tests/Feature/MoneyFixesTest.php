<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Documents\Create;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\RecurringInvoice;
use App\Models\Role;
use App\Models\User;
use App\Models\VipMembership;
use App\Services\Accounting\RecordsBusinessEvents;
use App\Services\DebitNotes;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Services\RecurringInvoices;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use App\Support\Vat;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The discount that never reached the books, and the francs that do not exist.
 *
 * A discounted invoice used to build its journal entry from mismatched sides:
 * the customer was debited the discounted total while revenue was credited the
 * pre-discount subtotal. The entry was out by exactly the discount, the ledger
 * refused it, and recordQuietly() swallowed the refusal — so the customer was
 * billed and the books never heard about the sale. Revenue, TVA collectée and
 * the 411 receivable were all silently missing.
 *
 * Alongside it, two smaller ways the money could go wrong: credit and debit
 * notes rounded to centimes on a currency that has none, and the over-credit
 * guard read its balance before taking the lock.
 */
class MoneyFixesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $customer;

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
            'vat_registered' => true,
            'vat_rate' => 19.25,
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    // ─────────────────────────── the discounted invoice reaches the books ──

    /**
     * The bug in its plainest form: before the fix this entry did not exist at
     * all. The sale was made, the customer was billed 107 325, and the books
     * recorded nothing — no revenue, no TVA, no receivable.
     */
    public function test_a_discounted_invoice_posts_a_journal_entry(): void
    {
        $invoice = $this->discountedInvoice(100000, 10.0);

        $entry = $this->entryFor($invoice);

        $this->assertNotNull($entry, 'A discounted invoice must reach the books.');
        $this->assertEntryBalances($entry);
    }

    public function test_the_posted_entry_matches_the_document_the_customer_holds(): void
    {
        // 100 000 list, 10% off: 90 000 net, 17 325 TVA, 107 325 TTC.
        $invoice = $this->discountedInvoice(100000, 10.0);

        $entry = $this->entryFor($invoice);
        $this->assertNotNull($entry);

        $byNumber = $entry->lines->groupBy(fn ($l) => $l->account->number);

        // The customer owes what the invoice says, TVA included.
        $this->assertSame((float) $invoice->total, (float) $byNumber['411']->sum('debit'));
        // The TVA collected is the TVA on the discounted base — the header figure.
        $this->assertSame((float) $invoice->tax_total, (float) $byNumber['443']->sum('credit'));
        // Revenue is credited at list price and the rabais carried on 709, so
        // the books show both what was asked and what was given away.
        $this->assertSame(100000.0, (float) $byNumber['701']->sum('credit'));
        $this->assertSame((float) $invoice->discount_total, (float) $byNumber['709']->sum('debit'));
    }

    /**
     * A company whose chart predates the 709 role must still get a balanced
     * entry: revenue net of the discount, rather than a silent posting failure
     * — which is exactly the bug being fixed.
     */
    public function test_a_chart_without_709_still_posts_revenue_net_of_the_discount(): void
    {
        LedgerAccount::query()->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('number', '709')
            ->delete();

        $invoice = $this->discountedInvoice(100000, 10.0);

        $entry = $this->entryFor($invoice);
        $this->assertNotNull($entry);
        $this->assertEntryBalances($entry);

        $byNumber = $entry->lines->groupBy(fn ($l) => $l->account->number);
        $this->assertSame(90000.0, (float) $byNumber['701']->sum('credit'));
    }

    public function test_the_api_discount_path_posts(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $id = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $this->customer->id,
            'issue' => true,
            'discount_percent' => 10,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100000]],
        ])->assertCreated()->json('data.id');

        $invoice = Document::findOrFail($id);
        $this->assertGreaterThan(0, (float) $invoice->discount_total);

        $entry = $this->entryFor($invoice);
        $this->assertNotNull($entry, 'An API-created discounted invoice must post.');
        $this->assertEntryBalances($entry);
    }

    public function test_the_vip_tier_path_posts(): void
    {
        VipMembership::create([
            'contact_id' => $this->customer->id,
            'tier_name' => 'Gold',
            'discount_percent' => 10,
            'price_paid' => 0,
            'currency' => 'XAF',
            'status' => VipMembership::ACTIVE,
            'starts_on' => now()->subDay()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
        ]);

        Livewire::actingAs($this->owner)
            ->test(Create::class, ['type' => 'invoice'])
            ->call('save', [
                'contact_id' => $this->customer->id,
                'issue_date' => now()->toDateString(),
                'due_date' => null,
                'notes' => '',
                'lines' => [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '100000']],
            ], true);

        $invoice = Document::where('type', DocumentType::Invoice)->latest()->firstOrFail();
        $this->assertGreaterThan(0, (float) $invoice->discount_total, 'The VIP tier should have discounted this.');

        $entry = $this->entryFor($invoice);
        $this->assertNotNull($entry, 'A VIP-discounted invoice must post.');
        $this->assertEntryBalances($entry);
    }

    public function test_the_recurring_locked_discount_path_posts(): void
    {
        $schedule = RecurringInvoice::create([
            'company_id' => $this->company->id,
            'contact_id' => $this->customer->id,
            'name' => 'Loyer mensuel',
            'frequency' => 'monthly',
            'interval' => 1,
            'discount_percent' => 10,
            'payment_terms_days' => 14,
            'auto_issue' => true,
            'status' => RecurringInvoice::ACTIVE,
            'starts_on' => now()->toDateString(),
            'next_run_on' => now()->toDateString(),
            'occurrences' => 0,
            'lines' => [['description' => 'Loyer', 'quantity' => 1, 'unit_price' => 100000]],
            'created_by' => $this->owner->id,
        ]);

        $invoice = app(RecurringInvoices::class)->generate($schedule);

        $this->assertGreaterThan(0, (float) $invoice->discount_total);

        $entry = $this->entryFor($invoice);
        $this->assertNotNull($entry, 'A recurring discounted invoice must post.');
        $this->assertEntryBalances($entry);
    }

    /** The shelter must still shelter — and still say so in the log. */
    public function test_a_recording_failure_is_still_swallowed_and_logged(): void
    {
        Log::spy();

        $result = app(RecordsBusinessEvents::class)->recordQuietly(function () {
            throw new RuntimeException('The books are on fire.');
        });

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message) => str_contains((string) $message, 'business event'));
    }

    // ─────────────────────────────── whole francs on credit and debit notes ──

    public function test_a_partial_credit_on_an_xaf_invoice_carries_no_fractional_francs(): void
    {
        $invoice = $this->plainInvoice(100000, 19250);

        // 40 000 of 119 250 — the proportional TVA is 6 455.55…, which is not
        // an amount of francs that exists.
        $note = app(DocumentConverter::class)->creditNote($invoice->fresh(), $this->owner, 40000);

        $this->assertWholeFrancs($note);
        $this->assertSame((float) $note->total, (float) $note->subtotal + (float) $note->tax_total);
    }

    public function test_crediting_an_xaf_invoice_in_pieces_leaves_no_residual_tva(): void
    {
        $invoice = $this->plainInvoice(100000, 19250);
        $converter = app(DocumentConverter::class);

        $converter->creditNote($invoice->fresh(), $this->owner, 40000);
        $converter->creditNote($invoice->fresh(), $this->owner, 40000);
        // The last slice takes whatever is left, TVA residue included.
        $converter->creditNote($invoice->fresh(), $this->owner, 39250);

        $notes = Document::where('parent_document_id', $invoice->id)
            ->where('type', DocumentType::CreditNote)->get();

        $this->assertSame(0.0, $converter->creditableAmount($invoice->fresh()));
        $this->assertSame(19250.0, (float) $notes->sum('tax_total'),
            'Fully credited means the TVA is fully reclaimed — not a franc left behind.');
        $this->assertSame(100000.0, (float) $notes->sum('subtotal'));
    }

    public function test_a_debit_note_against_an_xaf_invoice_carries_no_fractional_francs(): void
    {
        $invoice = $this->plainInvoice(100000, 19250);

        $note = app(DebitNotes::class)->raise($this->owner, 10000, 'Fret refacturé', against: $invoice);

        $this->assertWholeFrancs($note);
        $this->assertSame((float) $note->total, (float) $note->subtotal + (float) $note->tax_total);
    }

    public function test_a_standalone_xaf_debit_note_with_a_rate_stays_in_whole_francs(): void
    {
        $note = app(DebitNotes::class)->raise(
            $this->owner, 10000, 'Pénalité', customer: $this->customer, taxRate: 0.1925,
        );

        $this->assertWholeFrancs($note);
        $this->assertSame((float) $note->total, (float) $note->subtotal + (float) $note->tax_total);
    }

    // ───────────────────────────────────── the API stores the net, like the UI ──

    /**
     * The screen has always stored unit_price net of tax, whichever way the
     * business keys its prices. The API stored whatever the caller sent — so a
     * TTC-keyed company got gross unit prices from one door and net from the
     * other, and the same line meant two different things.
     */
    public function test_the_api_stores_the_net_unit_price_when_prices_are_keyed_ttc(): void
    {
        $this->company->forceFill(['prices_include_tax' => true])->save();
        app(CurrentCompany::class)->set($this->company->fresh());

        Sanctum::actingAs($this->owner, ['*']);

        $id = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $this->customer->id,
            'lines' => [['description' => 'Sac de ciment', 'quantity' => 1, 'unit_price' => 11925]],
        ])->assertCreated()->json('data.id');

        $line = DocumentLine::where('document_id', $id)->firstOrFail();

        // 11 925 TTC at 19.25% is 10 000 net — what the UI would have stored.
        $this->assertSame(10000.0, (float) $line->unit_price);
        $this->assertSame(10000.0, (float) $line->line_total);
    }

    // ───────────────────────────────────────────────────────────── helpers ──

    /** Create and issue an invoice the way the composer would, discount applied. */
    protected function discountedInvoice(float $listPrice, float $discountPercent): Document
    {
        $vat = Vat::forCompany(
            $this->company,
            [['quantity' => 1, 'unit_price' => $listPrice, 'description' => 'Fourniture']],
            $discountPercent,
        );

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $vat['subtotal'],
            'discount_total' => $vat['discount_total'],
            'tax_total' => $vat['tax_total'],
            'total' => $vat['total'],
            'balance' => $vat['total'],
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Fourniture',
            'quantity' => 1,
            'unit_price' => $vat['lines'][0]['unit_net'],
            'tax_amount' => $vat['lines'][0]['tax'],
            'line_total' => $vat['lines'][0]['net'],
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->owner);
    }

    /** An issued invoice with the figures given, no discount. */
    protected function plainInvoice(float $net, float $tax): Document
    {
        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $net,
            'tax_total' => $tax,
            'total' => $net + $tax,
            'balance' => $net + $tax,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Fourniture',
            'quantity' => 1,
            'unit_price' => $net,
            'tax_amount' => $tax,
            'line_total' => $net,
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->owner);
    }

    protected function entryFor(Document $document): ?JournalEntry
    {
        return JournalEntry::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('source_type', Document::class)
            ->where('source_id', $document->id)
            ->with('lines.account')
            ->first();
    }

    protected function assertEntryBalances(JournalEntry $entry): void
    {
        $debits = round((float) $entry->lines->sum('debit'), 2);
        $credits = round((float) $entry->lines->sum('credit'), 2);

        $this->assertSame($debits, $credits, 'Debits and credits must agree.');
        $this->assertGreaterThan(0, $debits);
    }

    /** Every money figure on the note is a whole number of francs. */
    protected function assertWholeFrancs(Document $note): void
    {
        foreach (['subtotal', 'tax_total', 'total'] as $field) {
            $value = (float) $note->{$field};
            $this->assertSame(round($value), $value, "{$field} of {$value} is not whole francs.");
        }
    }
}
