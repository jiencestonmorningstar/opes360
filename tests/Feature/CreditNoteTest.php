<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Documents\Show as DocumentScreen;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Item;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Services\PaymentRecorder;
use App\Services\Stock\StockLedger;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Giving money back.
 *
 * A credit note existed as an enum case and a conversion target, and did
 * nothing: it posted no journal entry, so the books went on claiming revenue
 * and a receivable from a sale the business had cancelled in writing; it moved
 * no stock, so returned goods never came back to the shelf; and it could be
 * issued against the same invoice as many times as anybody clicked.
 *
 * The customer's own balance was worse still, and not only for credit notes.
 * `contacts.balance` — the figure the customers list shows as "owing" and sorts
 * on — was decremented on payment and on void and incremented *nowhere*. A
 * customer invoiced 1 000 who paid 400 was recorded as owing minus 400.
 */
class CreditNoteTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $customer;

    protected Item $cement;

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

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);

        $this->cement = Item::create([
            'company_id' => $this->company->id,
            'name' => 'Ciment 50kg', 'sku' => 'CIM-50', 'type' => 'product',
            'price' => 6500, 'cost' => 5000, 'track_stock' => true, 'is_active' => true,
        ]);
    }

    // ──────────────────────────────────────────── what a customer owes ──

    public function test_issuing_an_invoice_puts_it_on_the_customers_account(): void
    {
        $this->invoice(1000);

        $this->assertSame(1000.0, (float) $this->customer->fresh()->balance);
    }

    /**
     * The bug in its plainest form. Before the fix this was minus 400: the
     * "owing" badge (which shows only above zero) never appeared, and the
     * customers list, which sorts by amount owed, put the worst debtors last.
     */
    public function test_paying_part_of_an_invoice_leaves_the_rest_owing(): void
    {
        $invoice = $this->invoice(1000);

        app(PaymentRecorder::class)->record($invoice->fresh(), $this->owner, 400, PaymentMethod::Cash);

        $this->assertSame(600.0, (float) $this->customer->fresh()->balance);
    }

    public function test_paying_in_full_clears_the_account(): void
    {
        $invoice = $this->invoice(1000);

        app(PaymentRecorder::class)->record($invoice->fresh(), $this->owner, 1000, PaymentMethod::Cash);

        $this->assertSame(0.0, (float) $this->customer->fresh()->balance);
    }

    public function test_voiding_an_invoice_takes_it_off_the_account(): void
    {
        $invoice = $this->invoice(1000);

        app(DocumentConverter::class)->void($invoice, $this->owner);

        $this->assertSame(0.0, (float) $this->customer->fresh()->balance);
    }

    public function test_a_quotation_never_reaches_the_account(): void
    {
        $this->invoice(1000, DocumentType::Quotation);

        $this->assertSame(0.0, (float) $this->customer->fresh()->balance);
    }

    /**
     * Recomputing from the documents rather than nudging a running total means
     * a figure knocked out of step by anything at all comes back by itself.
     */
    public function test_a_balance_knocked_out_of_step_repairs_itself(): void
    {
        $invoice = $this->invoice(1000);
        $this->customer->forceFill(['balance' => 999999])->save();

        app(PaymentRecorder::class)->record($invoice->fresh(), $this->owner, 100, PaymentMethod::Cash);

        $this->assertSame(900.0, (float) $this->customer->fresh()->balance);
    }

    // ───────────────────────────────────────────────── the credit note ──

    public function test_crediting_an_invoice_in_full_clears_what_is_owed(): void
    {
        $invoice = $this->invoice(1000);

        $note = app(DocumentConverter::class)->convert($invoice, $this->owner);

        $this->assertSame(DocumentType::CreditNote, $note->type);
        $this->assertSame(0.0, (float) $this->customer->fresh()->balance);
    }

    public function test_a_credit_note_reverses_the_sale_in_the_books(): void
    {
        $invoice = $this->invoice(1000);

        $revenue = LedgerAccount::query()->where('number', '701')->first();
        $receivable = LedgerAccount::query()->where('number', '411')->first();

        $this->assertSame(1000.0, $this->balanceOf($revenue));

        app(DocumentConverter::class)->convert($invoice, $this->owner);

        $this->assertSame(0.0, $this->balanceOf($revenue), 'A cancelled sale is not income.');
        $this->assertSame(0.0, $this->balanceOf($receivable));
    }

    /**
     * The TVA matters as much as the revenue: it is money the business would
     * otherwise hand to the state on a sale that did not happen.
     */
    public function test_a_credit_note_takes_back_the_tva_as_well(): void
    {
        $invoice = $this->invoice(1000, DocumentType::Invoice, tax: 192.50);

        $vat = LedgerAccount::query()->where('number', '443')->first();
        $this->assertSame(192.50, $this->balanceOf($vat));

        app(DocumentConverter::class)->convert($invoice->fresh(), $this->owner);

        $this->assertSame(0.0, $this->balanceOf($vat));
    }

    public function test_a_credit_note_puts_the_goods_back_on_the_shelf(): void
    {
        app(StockLedger::class)->receive($this->company, $this->cement, 100, 5000, null, $this->owner);

        $invoice = $this->invoiceForCement(12);
        $this->assertSame(88.0, $this->cement->fresh()->stockOnHand());

        app(DocumentConverter::class)->convert($invoice, $this->owner);

        $this->assertSame(100.0, $this->cement->fresh()->stockOnHand());
    }

    // ────────────────────────────────────────────── crediting in part ──

    public function test_part_of_an_invoice_can_be_credited(): void
    {
        $invoice = $this->invoice(1000);

        $note = app(DocumentConverter::class)->creditNote($invoice, $this->owner, 300, 'Deux sacs retournés');

        $this->assertSame(300.0, (float) $note->total);
        $this->assertSame(700.0, (float) $this->customer->fresh()->balance);
    }

    /**
     * The customer sees one figure — what they are getting back — and the tax
     * splits out behind it at the invoice's own rate, so the TVA reclaimed is
     * exactly the TVA charged on that part of the sale.
     */
    public function test_a_partial_credit_splits_the_tax_at_the_invoices_own_rate(): void
    {
        // 1 000 HT plus 19.25% is 1 192.50 TTC. Crediting 596.25 is half of it.
        $invoice = $this->invoice(1000, DocumentType::Invoice, tax: 192.50);

        $note = app(DocumentConverter::class)->creditNote($invoice->fresh(), $this->owner, 596.25);

        $this->assertSame(96.25, (float) $note->tax_total);
        $this->assertSame(500.0, (float) $note->subtotal);
        $this->assertSame(596.25, (float) $note->total);
    }

    public function test_several_partial_credits_add_up(): void
    {
        $invoice = $this->invoice(1000);
        $converter = app(DocumentConverter::class);

        $converter->creditNote($invoice, $this->owner, 300);
        $converter->creditNote($invoice->fresh(), $this->owner, 200);

        $this->assertSame(500.0, $converter->creditedTotal($invoice->fresh()));
        $this->assertSame(500.0, $converter->creditableAmount($invoice->fresh()));
        $this->assertSame(500.0, (float) $this->customer->fresh()->balance);
    }

    /**
     * The bug a second click would have caused: an invoice credited twice at
     * full value would leave the business recorded as owing its customer the
     * whole amount of a bill it had correctly raised.
     */
    public function test_an_invoice_cannot_be_credited_beyond_its_value(): void
    {
        $invoice = $this->invoice(1000);
        $converter = app(DocumentConverter::class);

        $converter->creditNote($invoice, $this->owner, 1000);

        $this->assertFalse($converter->canConvert($invoice->fresh()));

        $this->expectException(RuntimeException::class);
        $converter->creditNote($invoice->fresh(), $this->owner, 1);
    }

    public function test_a_partly_credited_invoice_cannot_be_credited_whole_by_conversion(): void
    {
        $invoice = $this->invoice(1000);
        $converter = app(DocumentConverter::class);

        $converter->creditNote($invoice, $this->owner, 300);

        $this->expectException(RuntimeException::class);
        $converter->convert($invoice->fresh(), $this->owner);
    }

    public function test_a_credit_note_for_nothing_is_refused(): void
    {
        $invoice = $this->invoice(1000);

        $this->expectException(RuntimeException::class);
        app(DocumentConverter::class)->creditNote($invoice, $this->owner, 0);
    }

    public function test_a_draft_invoice_has_nothing_to_credit(): void
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
        app(DocumentConverter::class)->creditNote($draft, $this->owner, 100);
    }

    public function test_only_an_invoice_can_be_credited(): void
    {
        $quotation = $this->invoice(500, DocumentType::Quotation);

        $this->expectException(RuntimeException::class);
        app(DocumentConverter::class)->creditNote($quotation, $this->owner, 100);
    }

    // ──────────────────────────────────────── voiding a credit note ──

    public function test_voiding_a_credit_note_puts_the_debt_back(): void
    {
        $invoice = $this->invoice(1000);
        $note = app(DocumentConverter::class)->creditNote($invoice, $this->owner, 300);

        $this->assertSame(700.0, (float) $this->customer->fresh()->balance);

        app(DocumentConverter::class)->void($note, $this->owner);

        $this->assertSame(1000.0, (float) $this->customer->fresh()->balance);
        $this->assertSame(0.0, app(DocumentConverter::class)->creditedTotal($invoice->fresh()));
    }

    public function test_voiding_a_credit_note_reverses_its_entry(): void
    {
        $invoice = $this->invoice(1000);
        $note = app(DocumentConverter::class)->convert($invoice, $this->owner);

        $revenue = LedgerAccount::query()->where('number', '701')->first();
        $this->assertSame(0.0, $this->balanceOf($revenue));

        app(DocumentConverter::class)->void($note, $this->owner);

        $this->assertSame(1000.0, $this->balanceOf($revenue), 'The sale stands again.');
    }

    public function test_voiding_a_credit_note_takes_the_goods_back_off_the_shelf(): void
    {
        app(StockLedger::class)->receive($this->company, $this->cement, 100, 5000, null, $this->owner);

        $invoice = $this->invoiceForCement(12);
        $note = app(DocumentConverter::class)->convert($invoice, $this->owner);

        $this->assertSame(100.0, $this->cement->fresh()->stockOnHand());

        app(DocumentConverter::class)->void($note, $this->owner);

        $this->assertSame(88.0, $this->cement->fresh()->stockOnHand(), 'The goods never actually came back.');
    }

    // ────────────────────────────────────────────────────── the screen ──

    public function test_the_invoice_screen_offers_a_credit_note(): void
    {
        $invoice = $this->invoice(1000);

        Livewire::actingAs($this->owner)
            ->test(DocumentScreen::class, ['document' => $invoice])
            ->assertSee('Issue a Credit Note')
            ->call('openCredit')
            ->assertSet('creditingOpen', true)
            ->assertSet('creditAmount', '1000.00');
    }

    public function test_the_screen_issues_a_partial_credit_and_shows_it_on_the_invoice(): void
    {
        $invoice = $this->invoice(1000);

        Livewire::actingAs($this->owner)
            ->test(DocumentScreen::class, ['document' => $invoice])
            ->call('openCredit')
            ->set('creditAmount', '250')
            ->set('creditReason', 'Sac abîmé')
            ->call('issueCredit')
            ->assertHasNoErrors();

        $this->assertSame(750.0, (float) $this->customer->fresh()->balance);

        Livewire::actingAs($this->owner)
            ->test(DocumentScreen::class, ['document' => $invoice->fresh()])
            ->assertViewHas('creditedTotal', 250.0)
            ->assertViewHas('creditableAmount', 750.0)
            ->assertSee('Sac abîmé');
    }

    public function test_the_screen_refuses_more_than_is_left(): void
    {
        $invoice = $this->invoice(1000);

        Livewire::actingAs($this->owner)
            ->test(DocumentScreen::class, ['document' => $invoice])
            ->call('openCredit')
            ->set('creditAmount', '1500')
            ->call('issueCredit')
            ->assertHasErrors('creditAmount');
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

    protected function invoiceForCement(float $quantity): Document
    {
        $total = $quantity * 6500;

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $total, 'total' => $total, 'balance' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'item_id' => $this->cement->id,
            'description' => $this->cement->name,
            'quantity' => $quantity,
            'unit_price' => 6500,
            'line_total' => $total,
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->owner);
    }

    /** The account's balance, signed its own way round. */
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
