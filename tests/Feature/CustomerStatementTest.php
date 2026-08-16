<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Reports\Statement as StatementScreen;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Role;
use App\Models\User;
use App\Services\DebitNotes;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Services\PaymentRecorder;
use App\Support\CurrentCompany;
use App\Support\Statement;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A statement of account: everything that passed between the business and one
 * customer over a period, and what is left at the end of it.
 *
 * The aging report answers "who do I chase"; a statement answers the question
 * the customer asks back — "for what?". It is the document a business sends
 * when a client's accounts department says their records disagree, and without
 * one the only reply available was a stack of re-sent invoices.
 */
class CustomerStatementTest extends TestCase
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

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    protected function statement(string $from = '2026-06-01', string $to = '2026-06-30'): array
    {
        return (new Statement($this->customer, Carbon::parse($from), Carbon::parse($to)))->build();
    }

    // ────────────────────────────────────────────────── the running tally ──

    public function test_an_invoice_in_the_period_is_a_debit(): void
    {
        $this->invoice(100000, '2026-06-10');

        $statement = $this->statement();

        $this->assertCount(1, $statement['lines']);
        $this->assertSame(100000.0, $statement['lines'][0]['debit']);
        $this->assertSame(0.0, $statement['lines'][0]['credit']);
        $this->assertSame(100000.0, $statement['closing_balance']);
    }

    public function test_a_payment_in_the_period_is_a_credit(): void
    {
        $invoice = $this->invoice(100000, '2026-06-10');
        $this->pay($invoice, 40000, '2026-06-20');

        $statement = $this->statement();

        $this->assertCount(2, $statement['lines']);
        $this->assertSame(40000.0, $statement['lines'][1]['credit']);
        $this->assertSame(60000.0, $statement['closing_balance']);
    }

    public function test_the_balance_runs_down_the_page(): void
    {
        $invoice = $this->invoice(100000, '2026-06-05');
        $this->pay($invoice, 30000, '2026-06-12');
        $this->invoice(50000, '2026-06-18');

        $lines = $this->statement()['lines'];

        $this->assertSame([100000.0, 70000.0, 120000.0], array_column($lines, 'balance'));
    }

    public function test_a_credit_note_reduces_the_balance(): void
    {
        $invoice = $this->invoice(100000, '2026-06-05');

        Carbon::setTestNow('2026-06-15');
        app(DocumentConverter::class)->creditNote($invoice->fresh(), $this->owner, 25000, 'Retour');
        Carbon::setTestNow();

        $statement = $this->statement();

        $this->assertSame(75000.0, $statement['closing_balance']);
        $this->assertSame(25000.0, $statement['totals']['credited']);
    }

    public function test_a_debit_note_increases_the_balance(): void
    {
        $invoice = $this->invoice(100000, '2026-06-05');

        Carbon::setTestNow('2026-06-15');
        app(DebitNotes::class)->raise($this->owner, 8000, 'Frais de port', $invoice->fresh());
        Carbon::setTestNow();

        $statement = $this->statement();

        $this->assertSame(108000.0, $statement['closing_balance']);
        $this->assertSame(108000.0, $statement['totals']['charged']);
    }

    // ─────────────────────────────────────────────────── period bounds ──

    /**
     * The point of an opening balance. Without it a statement for June, sent to
     * a customer who has owed money since March, reads as though the business
     * is only claiming June's invoices — and the customer pays that.
     */
    public function test_what_happened_before_the_period_becomes_the_opening_balance(): void
    {
        $this->invoice(300000, '2026-05-10');
        $this->invoice(100000, '2026-06-10');

        $statement = $this->statement();

        $this->assertSame(300000.0, $statement['opening_balance']);
        $this->assertCount(1, $statement['lines'], 'May is summarised, not listed.');
        $this->assertSame(400000.0, $statement['closing_balance']);
    }

    public function test_the_first_and_last_day_of_the_period_are_inside_it(): void
    {
        $this->invoice(10000, '2026-06-01');
        $this->invoice(20000, '2026-06-30');

        $this->assertCount(2, $this->statement()['lines']);
    }

    public function test_anything_after_the_period_is_left_out(): void
    {
        $this->invoice(10000, '2026-06-10');
        $this->invoice(999999, '2026-07-02');

        $statement = $this->statement();

        $this->assertCount(1, $statement['lines']);
        $this->assertSame(10000.0, $statement['closing_balance']);
    }

    // ──────────────────────────────────────────────── what is excluded ──

    public function test_a_draft_invoice_is_not_on_the_statement(): void
    {
        Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => '2026-06-10',
            'currency' => 'XAF',
            'subtotal' => 50000, 'total' => 50000, 'balance' => 50000,
        ]);

        $this->assertSame(0.0, $this->statement()['closing_balance']);
    }

    public function test_a_voided_invoice_is_not_on_the_statement(): void
    {
        $invoice = $this->invoice(50000, '2026-06-10');
        app(DocumentConverter::class)->void($invoice, $this->owner);

        $this->assertSame(0.0, $this->statement()['closing_balance']);
    }

    public function test_a_quotation_is_not_on_the_statement(): void
    {
        $this->invoice(50000, '2026-06-10', DocumentType::Quotation);

        $this->assertSame(0.0, $this->statement()['closing_balance']);
    }

    public function test_another_customers_invoice_is_not_on_the_statement(): void
    {
        $other = Contact::create(['name' => 'Autre Client', 'balance' => 0]);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $other->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => '2026-06-10',
            'currency' => 'XAF',
            'subtotal' => 70000, 'total' => 70000, 'balance' => 70000,
        ]);
        app(DocumentIssuer::class)->issue($document, $this->owner);

        $this->assertSame(0.0, $this->statement()['closing_balance']);
    }

    // ────────────────────────────────────────────────── reconciliation ──

    /**
     * The identity that makes the statement trustworthy: a statement running to
     * today must close on the same figure the customers list shows as owing.
     * If the two ever disagree, one of them is lying to somebody.
     */
    public function test_a_statement_to_today_closes_on_the_customers_balance(): void
    {
        $invoice = $this->invoice(100000, now()->subDays(20)->toDateString());
        $this->pay($invoice, 35000, now()->subDays(5)->toDateString());

        $statement = (new Statement($this->customer, now()->subYear(), now()))->build();

        $this->assertSame(
            round((float) $this->customer->fresh()->balance, 2),
            $statement['closing_balance'],
        );
    }

    public function test_the_statement_ages_what_is_still_open(): void
    {
        $this->invoice(100000, '2026-06-05', due: '2026-03-01');

        $statement = $this->statement();

        $this->assertSame(100000.0, $statement['aging']['over_90']);
        $this->assertSame(0.0, $statement['aging']['current']);
    }

    public function test_an_empty_period_still_carries_the_balance_forward(): void
    {
        $this->invoice(80000, '2026-05-01');

        $statement = $this->statement();

        $this->assertSame([], $statement['lines']);
        $this->assertSame(80000.0, $statement['opening_balance']);
        $this->assertSame(80000.0, $statement['closing_balance']);
    }

    // ─────────────────────────────────────────────────────── the screen ──

    public function test_the_screen_renders_a_statement_for_a_customer(): void
    {
        $this->invoice(100000, now()->subDays(3)->toDateString());

        Livewire::actingAs($this->owner)
            ->test(StatementScreen::class)
            ->set('contactId', $this->customer->id)
            ->assertSee('Un Client')
            ->assertSee('Closing balance');
    }

    public function test_the_screen_exports_a_csv(): void
    {
        $this->invoice(100000, now()->subDays(3)->toDateString());

        $response = Livewire::actingAs($this->owner)
            ->test(StatementScreen::class)
            ->set('contactId', $this->customer->id)
            ->call('export');

        $response->assertOk();
    }

    public function test_the_screen_refuses_a_period_that_runs_backwards(): void
    {
        Livewire::actingAs($this->owner)
            ->test(StatementScreen::class)
            ->set('contactId', $this->customer->id)
            ->set('from', '2026-06-30')
            ->set('to', '2026-06-01')
            ->assertHasErrors('to');
    }

    // ───────────────────────────────────────────────────────── helpers ──

    protected function invoice(
        float $total,
        string $issuedOn,
        DocumentType $type = DocumentType::Invoice,
        ?string $due = null,
    ): Document {
        $document = Document::create([
            'type' => $type,
            'contact_id' => $this->customer->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => $issuedOn,
            'due_date' => $due ?? Carbon::parse($issuedOn)->addDays(14)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $total, 'tax_total' => 0, 'total' => $total, 'balance' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Fourniture',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->owner);
    }

    protected function pay(Document $document, float $amount, string $on): void
    {
        app(PaymentRecorder::class)->record(
            $document->fresh(),
            $this->owner,
            $amount,
            PaymentMethod::Cash,
            receivedAt: Carbon::parse($on),
        );
    }
}
