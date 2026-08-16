<?php

namespace Tests\Feature\Reports;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Reports\Executive;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\Banking\CashForecast;
use App\Support\Aging;
use App\Support\CurrentCompany;
use App\Support\Kpis;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The executive screen exists to summarise reports that already exist, so the
 * only test that matters is agreement: every KPI must equal what the report it
 * summarises says for the same window. A dashboard number that disagrees with
 * its own report is worse than no dashboard.
 */
class ExecutiveTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

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

    protected function invoice(string $issueDate, float $total, float $paid = 0, ?Contact $contact = null): Document
    {
        $balance = round($total - $paid, 2);

        return Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => ($contact ?? $this->customer)->id,
            'status' => $balance <= 0 ? DocumentStatus::Paid->value : ($paid > 0 ? 'partial' : 'issued'),
            'number' => 'INV-'.Str::upper(Str::random(6)),
            'issue_date' => $issueDate,
            'due_date' => CarbonImmutable::parse($issueDate)->addDays(30)->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $total,
            'discount_total' => 0,
            'tax_total' => 0,
            'total' => $total,
            'amount_paid' => $paid,
            'balance' => $balance,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function bill(string $issueDate, float $total, string $dueDate, float $paid = 0): Expense
    {
        return Expense::create([
            'supplier_id' => $this->customer->id,
            'number' => 'EXP-'.Str::upper(Str::random(5)),
            'description' => 'Supplies',
            'category' => 'general',
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'amount' => $total, 'vat_rate' => 0, 'vat_amount' => 0,
            'total' => $total, 'amount_paid' => $paid,
            'currency' => 'XAF', 'status' => 'recorded',
            'recorded_by' => $this->owner->id,
        ]);
    }

    protected function payment(string $receivedAt, float $amount, ?Document $for = null): Payment
    {
        $payment = Payment::create([
            'contact_id' => $this->customer->id,
            'method' => 'cash',
            'amount' => $amount,
            'currency' => 'XAF',
            'received_at' => $receivedAt,
            'received_by' => $this->owner->id,
        ]);

        if ($for !== null) {
            PaymentAllocation::create([
                'payment_id' => $payment->id,
                'document_id' => $for->id,
                'amount' => $amount,
            ]);
        }

        return $payment;
    }

    protected function kpis(string $from, string $to): array
    {
        return (new Kpis(
            $this->company,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
        ))->summary();
    }

    // ── Agreement with the underlying reports ────────────────────────────

    public function test_revenue_matches_the_sales_report_for_the_same_window(): void
    {
        $this->invoice('2026-06-05', 100000);
        $this->invoice('2026-06-20', 250000);
        $this->invoice('2026-05-28', 999999); // outside the window

        $kpis = $this->kpis('2026-06-01', '2026-06-30');

        // Exactly what Reports\Index computes for June.
        $reported = (float) Document::query()->invoices()
            ->issuedBetween('2026-06-01', '2026-06-30')->sum('total');

        $this->assertSame(350000.0, $kpis['period']['revenue']);
        $this->assertSame($reported, $kpis['period']['revenue']);
    }

    /** Date casts store midnight; a bare whereBetween drops the last day. */
    public function test_an_invoice_on_the_last_day_of_the_period_is_counted(): void
    {
        $this->invoice('2026-06-30', 40000);

        $this->assertSame(40000.0, $this->kpis('2026-06-01', '2026-06-30')['period']['revenue']);
    }

    public function test_net_result_matches_the_income_statement(): void
    {
        // Whatever the books say for the window — including an empty ledger,
        // where both must agree on zero — the KPI repeats, never recomputes.
        $expected = app(Books::class)
            ->incomeStatement($this->company, '2026-06-01', '2026-06-30')['resultat'];

        $this->assertSame($expected, $this->kpis('2026-06-01', '2026-06-30')['period']['net_result']);
    }

    public function test_cash_position_matches_the_cash_forecast(): void
    {
        $this->assertSame(
            app(CashForecast::class)->currentCashPosition($this->company),
            $this->kpis('2026-06-01', '2026-06-30')['now']['cash_position'],
        );
    }

    public function test_receivables_figures_match_the_aging_report(): void
    {
        CarbonImmutable::setTestNow('2026-06-30');

        $this->invoice('2026-03-01', 80000);  // long overdue
        $this->invoice('2026-06-25', 50000);  // not yet due

        $aging = (new Aging)->receivable();
        $kpis = $this->kpis('2026-06-01', '2026-06-30');

        $this->assertSame($aging['total'], $kpis['now']['receivables_outstanding']);
        $this->assertSame(
            round($aging['total'] - $aging['totals']['current'], 2),
            $kpis['now']['receivables_overdue'],
        );
        // 80k of 130k is overdue.
        $this->assertEqualsWithDelta(61.5, $kpis['now']['receivables_overdue_share'], 0.1);

        CarbonImmutable::setTestNow();
    }

    public function test_payables_due_soon_covers_overdue_and_the_next_fortnight(): void
    {
        CarbonImmutable::setTestNow('2026-06-30');

        $this->bill('2026-05-01', 30000, '2026-05-31'); // overdue
        $this->bill('2026-06-20', 20000, '2026-07-10'); // due inside 14 days
        $this->bill('2026-06-20', 90000, '2026-09-30'); // far in the future

        $this->assertSame(50000.0, $this->kpis('2026-06-01', '2026-06-30')['now']['payables_due_soon']);

        CarbonImmutable::setTestNow();
    }

    public function test_average_days_to_payment_is_weighted_by_amount(): void
    {
        $fast = $this->invoice('2026-06-01', 10000, 10000);
        $slow = $this->invoice('2026-05-01', 30000, 30000);

        $this->payment('2026-06-11', 10000, $fast); // 10 days
        $this->payment('2026-06-20', 30000, $slow); // 50 days

        // (10000×10 + 30000×50) / 40000 = 40
        $this->assertSame(40.0, $this->kpis('2026-06-01', '2026-06-30')['period']['average_days_to_payment']);
    }

    public function test_expenses_sum_the_recorded_bills_in_the_window(): void
    {
        $this->bill('2026-06-10', 25000, '2026-07-10');
        $this->bill('2026-06-30', 15000, '2026-07-30'); // last day again
        $this->bill('2026-07-01', 99999, '2026-08-01'); // outside

        $this->assertSame(40000.0, $this->kpis('2026-06-01', '2026-06-30')['period']['expenses']);
    }

    // ── Prior-period comparison ──────────────────────────────────────────

    public function test_the_previous_period_is_the_same_length_immediately_before(): void
    {
        $this->invoice('2026-06-10', 200000);
        $this->invoice('2026-05-10', 100000);

        $kpis = $this->kpis('2026-06-01', '2026-06-30');

        $this->assertSame(100000.0, $kpis['previous']['revenue']);
        $this->assertSame(100.0, $kpis['deltas']['revenue']);
    }

    public function test_a_delta_from_zero_is_null_not_infinity(): void
    {
        $this->invoice('2026-06-10', 200000);

        $this->assertNull($this->kpis('2026-06-01', '2026-06-30')['deltas']['revenue']);
    }

    // ── Tenancy ──────────────────────────────────────────────────────────

    public function test_another_companys_figures_never_leak_in(): void
    {
        $this->invoice('2026-06-10', 100000);

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        Document::withoutEvents(fn () => Document::query()->insert([
            'id' => Str::ulid()->toBase32(),
            'company_id' => $other->id,
            'type' => 'invoice',
            'status' => 'issued',
            'number' => 'INV-LEAK',
            'issue_date' => '2026-06-15',
            'currency' => 'XAF',
            'subtotal' => 555555, 'discount_total' => 0, 'tax_total' => 0,
            'total' => 555555, 'amount_paid' => 0, 'balance' => 555555,
            'created_by' => $otherOwner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        $kpis = $this->kpis('2026-06-01', '2026-06-30');

        $this->assertSame(100000.0, $kpis['period']['revenue']);
        $this->assertSame(100000.0, $kpis['now']['receivables_outstanding']);
    }

    // ── The screen ───────────────────────────────────────────────────────

    public function test_the_executive_screen_renders_for_a_permitted_user(): void
    {
        $this->invoice('2026-06-10', 100000);

        Livewire::actingAs($this->owner)
            ->test(Executive::class)
            ->assertOk()
            ->assertSee('Executive');
    }

    public function test_profitability_by_customer_matches_the_documents(): void
    {
        $b = Contact::create(['name' => 'Deux Client', 'balance' => 0]);

        $this->invoice('2026-06-05', 300000);
        $this->invoice('2026-06-06', 100000, 100000, $b);

        $rows = (new Kpis(
            $this->company,
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-06-30'),
        ))->customerProfitability();

        $this->assertSame(300000.0, $rows->first()['revenue']);
        $this->assertSame($this->customer->id, $rows->first()['contact']->id);
        $this->assertSame(100000.0, $rows->last()['revenue']);
    }
}
