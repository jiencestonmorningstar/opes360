<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\DebitNotes;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Services\PaymentRecorder;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Charging a customer more after the fact.
 *
 * The mirror of the credit note, and the one half of the pair the platform did
 * not have. A business that undercharged, shipped extra, or wants to bill a
 * late-payment fee had only two options: edit an issued invoice, which the
 * immutability rule forbids, or raise a second invoice, which pretends a second
 * sale happened and double-counts the goods on the stock ledger.
 */
class DebitNoteTest extends TestCase
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
            'slug' => 'acme-'.Str::lower(Str::random(6)),
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

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    // ───────────────────────────────────────────── against an invoice ──

    public function test_a_debit_note_adds_to_what_the_customer_owes(): void
    {
        $invoice = $this->invoice(1000);

        $note = app(DebitNotes::class)->raise($this->owner, 250, 'Frais de livraison omis', $invoice);

        $this->assertSame(DocumentType::DebitNote, $note->type);
        $this->assertSame(250.0, (float) $note->total);
        $this->assertSame(1250.0, (float) $this->customer->fresh()->balance);
    }

    public function test_a_debit_note_is_issued_immediately_with_its_own_number(): void
    {
        $note = app(DebitNotes::class)->raise($this->owner, 250, 'Frais', $this->invoice(1000));

        $this->assertSame(DocumentStatus::Issued, $note->status);
        $this->assertStringStartsWith('DBN-', (string) $note->number);
    }

    public function test_it_remembers_the_invoice_it_corrects(): void
    {
        $invoice = $this->invoice(1000);

        $note = app(DebitNotes::class)->raise($this->owner, 250, 'Frais', $invoice);

        $this->assertSame($invoice->id, $note->parent_document_id);
        $this->assertSame($invoice->contact_id, $note->contact_id);
        $this->assertSame(250.0, app(DebitNotes::class)->debitedTotal($invoice));
    }

    /** The same three accounts as an invoice, the same way round. */
    public function test_a_debit_note_posts_a_sale_to_the_books(): void
    {
        $invoice = $this->invoice(1000);

        $revenue = LedgerAccount::query()->where('number', '701')->first();
        $receivable = LedgerAccount::query()->where('number', '411')->first();

        app(DebitNotes::class)->raise($this->owner, 250, 'Frais', $invoice);

        $this->assertSame(1250.0, $this->balanceOf($revenue));
        $this->assertSame(1250.0, $this->balanceOf($receivable));
    }

    /**
     * The customer is quoted one figure — what they now owe on top — and the
     * tax splits out behind it at the invoice's own rate, so the TVA the
     * business declares on the correction matches the rate it charged on the
     * sale being corrected.
     */
    public function test_the_tax_splits_at_the_invoices_own_rate(): void
    {
        // 1 000 HT plus 19.25% is 1 192.50 TTC.
        $invoice = $this->invoice(1000, tax: 192.50);

        $note = app(DebitNotes::class)->raise($this->owner, 596.25, 'Complément', $invoice->fresh());

        $this->assertSame(96.25, (float) $note->tax_total);
        $this->assertSame(500.0, (float) $note->subtotal);
        $this->assertSame(596.25, (float) $note->total);
    }

    public function test_the_tva_reaches_the_books(): void
    {
        $invoice = $this->invoice(1000, tax: 192.50);

        app(DebitNotes::class)->raise($this->owner, 596.25, 'Complément', $invoice->fresh());

        $vat = LedgerAccount::query()->where('number', '443')->first();

        $this->assertSame(288.75, $this->balanceOf($vat));
    }

    // ─────────────────────────────────────────────────── standalone ──

    /**
     * A late-payment fee belongs to the customer, not to any one invoice, and
     * carries no tax unless somebody says otherwise: in most regimes interest
     * charged for late settlement is outside the scope of VAT.
     */
    public function test_a_debit_note_can_stand_on_its_own_against_a_customer(): void
    {
        $note = app(DebitNotes::class)->raise($this->owner, 5000, 'Pénalité de retard', null, $this->customer);

        $this->assertNull($note->parent_document_id);
        $this->assertSame(5000.0, (float) $note->total);
        $this->assertSame(0.0, (float) $note->tax_total);
        $this->assertSame(5000.0, (float) $this->customer->fresh()->balance);
    }

    public function test_an_explicit_tax_rate_is_split_out_of_the_gross(): void
    {
        $note = app(DebitNotes::class)->raise(
            $this->owner, 1192.50, 'Prestation omise', null, $this->customer, taxRate: 0.1925
        );

        $this->assertSame(1000.0, (float) $note->subtotal);
        $this->assertSame(192.50, (float) $note->tax_total);
    }

    public function test_it_falls_due_on_the_customers_terms(): void
    {
        $this->customer->forceFill(['payment_terms_days' => 30])->save();

        $note = app(DebitNotes::class)->raise($this->owner, 5000, 'Pénalité', null, $this->customer->fresh());

        $this->assertSame(now()->addDays(30)->toDateString(), $note->due_date?->toDateString());
    }

    // ─────────────────────────────────────────────────────── refusals ──

    public function test_a_debit_note_for_nothing_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(DebitNotes::class)->raise($this->owner, 0, 'Rien', null, $this->customer);
    }

    public function test_a_negative_debit_note_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        app(DebitNotes::class)->raise($this->owner, -100, 'Négatif', null, $this->customer);
    }

    public function test_it_needs_somebody_to_charge(): void
    {
        $this->expectException(RuntimeException::class);

        app(DebitNotes::class)->raise($this->owner, 100, 'Sans client');
    }

    public function test_a_draft_invoice_cannot_be_debited(): void
    {
        $draft = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 500, 'total' => 500, 'balance' => 500,
        ]);

        $this->expectException(RuntimeException::class);

        app(DebitNotes::class)->raise($this->owner, 100, 'Frais', $draft);
    }

    public function test_a_voided_invoice_cannot_be_debited(): void
    {
        $invoice = $this->invoice(1000);
        app(DocumentConverter::class)->void($invoice, $this->owner);

        $this->expectException(RuntimeException::class);

        app(DebitNotes::class)->raise($this->owner, 100, 'Frais', $invoice->fresh());
    }

    public function test_only_an_invoice_can_be_debited(): void
    {
        $quotation = $this->invoice(500, type: DocumentType::Quotation);

        $this->expectException(RuntimeException::class);

        app(DebitNotes::class)->raise($this->owner, 100, 'Frais', $quotation);
    }

    public function test_a_reason_is_required(): void
    {
        $this->expectException(RuntimeException::class);

        app(DebitNotes::class)->raise($this->owner, 100, '   ', null, $this->customer);
    }

    // ───────────────────────────────────────────────────── voiding ──

    public function test_voiding_a_debit_note_takes_the_extra_charge_back_off(): void
    {
        $invoice = $this->invoice(1000);
        $note = app(DebitNotes::class)->raise($this->owner, 250, 'Frais', $invoice);

        app(DocumentConverter::class)->void($note, $this->owner);

        $this->assertSame(1000.0, (float) $this->customer->fresh()->balance);
        $this->assertSame(0.0, app(DebitNotes::class)->debitedTotal($invoice->fresh()));
        $this->assertSame(1000.0, $this->balanceOf(LedgerAccount::query()->where('number', '701')->first()));
    }

    /**
     * A debit note is a receivable in its own right: money is owed on it, on a
     * date, and a payment settles it exactly as it settles an invoice.
     */
    public function test_a_debit_note_can_be_paid(): void
    {
        $note = app(DebitNotes::class)->raise($this->owner, 5000, 'Pénalité', null, $this->customer);

        app(PaymentRecorder::class)->record(
            $note->fresh(), $this->owner, 5000, PaymentMethod::Cash
        );

        $this->assertSame(0.0, (float) $note->fresh()->balance);
        $this->assertSame(0.0, (float) $this->customer->fresh()->balance);
    }

    // ───────────────────────────────────────────────────────── helpers ──

    protected function invoice(float $net, DocumentType $type = DocumentType::Invoice, float $tax = 0): Document
    {
        $document = Document::create([
            'type' => $type,
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

    protected function balanceOf(?LedgerAccount $account): float
    {
        if ($account === null) {
            return 0.0;
        }

        $lines = JournalLine::query()->withoutGlobalScopes()->where('ledger_account_id', $account->id)->get();
        $signed = $lines->sum(fn (JournalLine $l) => (float) $l->debit - (float) $l->credit);

        return round($account->isDebitNormal() ? $signed : -$signed, 2);
    }
}
