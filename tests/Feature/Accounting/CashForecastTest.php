<?php

namespace Tests\Feature\Accounting;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Expense;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Ledger;
use App\Services\Banking\CashForecast;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CashForecastTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Contact $party;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl', 'owner_id' => $this->owner->id,
            'currency' => 'XAF', 'plan' => 'business', 'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);

        $this->party = Contact::create(['name' => 'A Customer', 'balance' => 0]);
    }

    public function test_a_business_with_nothing_outstanding_forecasts_flat(): void
    {
        $forecast = $this->forecast();

        $this->assertSame(0.0, $forecast['opening']);
        $this->assertFalse($forecast['goes_negative']);
    }

    public function test_the_opening_position_comes_from_the_ledger(): void
    {
        $this->cashIn(80_000);

        $this->assertSame(80_000.0, $this->forecast()['opening']);
    }

    public function test_an_invoice_due_next_week_shows_as_a_receipt_that_week(): void
    {
        $monday = CarbonImmutable::parse('2026-03-02');
        $this->invoice(30_000, $monday->addWeeks(1)->addDay());

        $weeks = $this->forecast($monday)['weeks'];

        $this->assertSame(0.0, $weeks[0]['receipts']);
        $this->assertSame(30_000.0, $weeks[1]['receipts']);
    }

    public function test_a_bill_due_shows_as_a_payment(): void
    {
        $monday = CarbonImmutable::parse('2026-03-02');
        $this->bill(12_000, $monday->addWeeks(1)->addDay());

        $weeks = $this->forecast($monday)['weeks'];

        $this->assertSame(12_000.0, $weeks[1]['payments']);
        $this->assertSame(-12_000.0, $weeks[1]['net']);
    }

    public function test_the_closing_balance_runs_forward_week_on_week(): void
    {
        $monday = CarbonImmutable::parse('2026-03-02');
        $this->cashIn(10_000);
        $this->invoice(5_000, $monday->addWeeks(1)->addDay());

        $weeks = $this->forecast($monday)['weeks'];

        $this->assertSame(10_000.0, $weeks[0]['closing']);
        $this->assertSame(15_000.0, $weeks[1]['closing']);
    }

    /** The one fact worth surfacing: a business heading for zero should know now. */
    public function test_a_shortfall_is_flagged(): void
    {
        $monday = CarbonImmutable::parse('2026-03-02');
        $this->bill(50_000, $monday->addWeeks(2)->addDay());

        $forecast = $this->forecast($monday);

        $this->assertTrue($forecast['goes_negative']);
        $this->assertSame(-50_000.0, $forecast['lowest']);
    }

    /**
     * Overdue money is still being chased. Dropping it would understate the
     * position as badly as assuming it arrives on time overstates it.
     */
    public function test_an_overdue_invoice_is_expected_soon_rather_than_dropped(): void
    {
        $monday = CarbonImmutable::parse('2026-03-02');
        $this->invoice(20_000, $monday->subMonths(2));

        $weeks = $this->forecast($monday)['weeks'];

        $this->assertSame(20_000.0, $weeks[0]['receipts']);
    }

    public function test_money_beyond_the_horizon_is_left_out(): void
    {
        $monday = CarbonImmutable::parse('2026-03-02');
        $this->invoice(99_000, $monday->addWeeks(20));

        $forecast = $this->forecast($monday, 4);

        $this->assertSame(0.0, $forecast['weeks']->sum('receipts'));
    }

    public function test_the_number_of_weeks_is_configurable(): void
    {
        $this->assertCount(4, $this->forecast(null, 4)['weeks']);
        $this->assertCount(12, $this->forecast(null, 12)['weeks']);
    }

    protected function forecast(?CarbonImmutable $from = null, int $weeks = 8): array
    {
        return app(CashForecast::class)->project($this->company, $weeks, $from);
    }

    protected function cashIn(float $amount): void
    {
        app(Ledger::class)->post($this->company, 'VE', '2026-01-05', [
            ['account' => 'cash', 'debit' => $amount],
            ['account' => 'sales_goods', 'credit' => $amount],
        ]);
    }

    protected function invoice(float $amount, CarbonImmutable $dueOn): Document
    {
        return Document::create([
            'type' => DocumentType::Invoice,
            'number' => 'INV-'.Str::upper(Str::random(5)),
            'contact_id' => $this->party->id,
            'status' => DocumentStatus::Issued,
            'issue_date' => $dueOn->subDays(30)->toDateString(),
            'due_date' => $dueOn->toDateString(),
            'currency' => 'XAF',
            'subtotal' => $amount, 'total' => $amount,
            'amount_paid' => 0, 'balance' => $amount,
        ]);
    }

    protected function bill(float $amount, CarbonImmutable $dueOn): Expense
    {
        return Expense::create([
            'description' => 'Supplier bill', 'category' => 'other',
            'issue_date' => $dueOn->subDays(30)->toDateString(),
            'due_date' => $dueOn->toDateString(),
            'amount' => $amount, 'total' => $amount, 'amount_paid' => 0,
            'status' => 'recorded', 'recorded_by' => $this->owner->id,
        ]);
    }
}
