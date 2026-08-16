<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\PaymentRun;
use App\Models\PaymentRunItem;
use App\Models\Role;
use App\Models\User;
use App\Services\Payables\PaymentScheduler;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use App\Support\PaymentSchedule;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Deciding which bills get paid, and when.
 *
 * The aging report already says which bills are late. What it cannot say is
 * which of them this week's cash actually reaches — and a business that pays
 * bills in the order they surface pays a small supplier on Tuesday and then
 * cannot make payroll on Friday.
 */
class PaymentSchedulingTest extends TestCase
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
    }

    protected function supplier(string $name): Contact
    {
        return Contact::create(['name' => $name, 'type' => 'supplier', 'balance' => 0]);
    }

    protected function bill(Contact $supplier, float $total, int $dueInDays, string $reference = 'SUP-1'): Expense
    {
        $expense = new Expense([
            'supplier_id' => $supplier->id,
            'reference' => $reference,
            'description' => 'Bill from '.$supplier->name,
            'category' => 'supplies',
            'issue_date' => now()->subDays(max(1, 30 - $dueInDays))->toDateString(),
            'due_date' => now()->addDays($dueInDays)->toDateString(),
            'amount' => $total,
            'vat_rate' => 0,
            'currency' => 'XAF',
            'status' => 'recorded',
            // Model::create does not backfill DB defaults into the returned
            // model, and the schedule divides by the balance.
            'amount_paid' => 0,
        ]);

        $expense->recompute();
        $expense->save();

        return $expense;
    }

    public function test_overdue_bills_outrank_bills_that_are_merely_large(): void
    {
        $late = $this->supplier('Late Sarl');
        $big = $this->supplier('Big Sarl');

        $this->bill($late, 100_000, -75, 'LATE-1');
        $this->bill($big, 900_000, 20, 'BIG-1');

        $bills = (new PaymentSchedule($this->company))->bills();

        $this->assertSame('LATE-1', $bills->first()['reference']);
        $this->assertSame(PaymentSchedule::BAND_OVERDUE, $bills->first()['band']);
        $this->assertSame(PaymentSchedule::BAND_LATER, $bills->last()['band']);
    }

    public function test_bills_not_yet_due_within_the_horizon_come_before_distant_ones(): void
    {
        $soon = $this->supplier('Soon Sarl');
        $later = $this->supplier('Later Sarl');

        $this->bill($soon, 50_000, 3, 'SOON-1');
        $this->bill($later, 50_000, 60, 'LATER-1');

        $bills = (new PaymentSchedule($this->company))->bills();

        $this->assertSame('SOON-1', $bills->first()['reference']);
        $this->assertSame(PaymentSchedule::BAND_DUE_SOON, $bills->first()['band']);
    }

    public function test_a_settled_bill_is_not_scheduled(): void
    {
        $supplier = $this->supplier('Paid Sarl');
        $bill = $this->bill($supplier, 40_000, -10, 'PAID-1');
        $bill->forceFill(['amount_paid' => 40_000])->save();

        $this->assertTrue((new PaymentSchedule($this->company))->bills()->isEmpty());
    }

    public function test_the_plan_funds_what_the_cash_reaches_and_defers_the_rest(): void
    {
        $a = $this->supplier('A Sarl');
        $b = $this->supplier('B Sarl');

        $this->bill($a, 60_000, -40, 'A-1');
        $this->bill($b, 60_000, -5, 'B-1');

        // 70,000 in hand: the oldest bill is covered outright, the second is
        // part-funded with what is left, and nothing pretends otherwise.
        $plan = (new PaymentSchedule($this->company))->plan(70_000);

        $items = collect($plan['items'])->keyBy('reference');

        $this->assertSame(60_000.0, $items['A-1']['scheduled']);
        $this->assertSame('fund', $items['A-1']['decision']);
        $this->assertSame(10_000.0, $items['B-1']['scheduled']);
        $this->assertSame('part', $items['B-1']['decision']);

        $this->assertSame(70_000.0, $plan['total_scheduled']);
        $this->assertSame(50_000.0, $plan['shortfall']);
    }

    public function test_a_reserve_is_held_back_from_the_cash_the_plan_may_spend(): void
    {
        $supplier = $this->supplier('Res Sarl');
        $this->bill($supplier, 100_000, -10, 'RES-1');

        $plan = (new PaymentSchedule($this->company, reserve: 30_000))->plan(100_000);

        // The reserve is not cash: it is cash the plan is forbidden to touch,
        // which is the only way payroll survives a heavy payables week.
        $this->assertSame(70_000.0, $plan['cash_available']);
        $this->assertSame(70_000.0, $plan['total_scheduled']);
        $this->assertSame(30_000.0, $plan['reserve']);
    }

    public function test_the_plan_reads_its_cash_from_the_forecast_when_none_is_given(): void
    {
        $supplier = $this->supplier('Fore Sarl');
        $this->bill($supplier, 10_000, -3, 'FORE-1');

        $plan = (new PaymentSchedule($this->company))->plan();

        // No ledger cash and no receipts due, so the honest answer is nothing
        // available — not "pay it anyway".
        $this->assertSame(0.0, $plan['cash_available']);
        $this->assertSame('defer', $plan['items'][0]['decision']);
    }

    public function test_a_run_is_created_from_the_plan_with_only_funded_lines(): void
    {
        $a = $this->supplier('Run A');
        $b = $this->supplier('Run B');

        $this->bill($a, 30_000, -20, 'RUN-A');
        $this->bill($b, 30_000, -1, 'RUN-B');

        $run = app(PaymentScheduler::class)->buildRun(
            new PaymentSchedule($this->company),
            ['scheduled_for' => now()->toDateString(), 'method' => 'bank', 'cash' => 40_000],
            $this->owner,
        );

        $this->assertSame(PaymentRun::STATUS_DRAFT, $run->status);
        $this->assertSame(40_000.0, (float) $run->cash_available);
        $this->assertCount(2, $run->items);
        $this->assertSame(40_000.0, $run->total());
        $this->assertSame(0.0, $run->headroom());
    }

    public function test_executing_a_run_settles_every_bill_in_it(): void
    {
        $supplier = $this->supplier('Exec Sarl');
        $bill = $this->bill($supplier, 25_000, -10, 'EXEC-1');

        $scheduler = app(PaymentScheduler::class);

        $run = $scheduler->buildRun(
            new PaymentSchedule($this->company),
            ['scheduled_for' => now()->toDateString(), 'method' => 'bank', 'cash' => 25_000],
            $this->owner,
        );

        $scheduler->approve($run, $this->owner);
        $scheduler->execute($run, $this->owner);

        $run->refresh()->load('items');

        $this->assertSame(PaymentRun::STATUS_EXECUTED, $run->status);
        $this->assertSame(PaymentRunItem::STATUS_PAID, $run->items->first()->status);
        $this->assertNotNull($run->items->first()->expense_payment_id);
        $this->assertTrue($bill->refresh()->isPaid());
    }

    public function test_a_run_cannot_be_executed_twice(): void
    {
        $supplier = $this->supplier('Twice Sarl');
        $this->bill($supplier, 15_000, -10, 'TWICE-1');

        $scheduler = app(PaymentScheduler::class);
        $run = $scheduler->buildRun(
            new PaymentSchedule($this->company),
            ['scheduled_for' => now()->toDateString(), 'method' => 'bank', 'cash' => 15_000],
            $this->owner,
        );

        $scheduler->approve($run, $this->owner);
        $scheduler->execute($run, $this->owner);

        $this->expectException(RuntimeException::class);
        $scheduler->execute($run->refresh(), $this->owner);
    }

    public function test_a_skipped_line_is_not_paid_when_the_run_executes(): void
    {
        $a = $this->supplier('Keep Sarl');
        $b = $this->supplier('Drop Sarl');

        $kept = $this->bill($a, 10_000, -20, 'KEEP-1');
        $dropped = $this->bill($b, 10_000, -10, 'DROP-1');

        $scheduler = app(PaymentScheduler::class);
        $run = $scheduler->buildRun(
            new PaymentSchedule($this->company),
            ['scheduled_for' => now()->toDateString(), 'method' => 'bank', 'cash' => 20_000],
            $this->owner,
        );

        $scheduler->skip($run->items->firstWhere('expense_id', $dropped->id), 'Query on the invoice');
        $scheduler->approve($run, $this->owner);
        $scheduler->execute($run->refresh(), $this->owner);

        $this->assertTrue($kept->refresh()->isPaid());
        $this->assertFalse($dropped->refresh()->isPaid());
    }

    public function test_a_run_that_was_never_approved_cannot_be_executed(): void
    {
        $supplier = $this->supplier('Unapproved Sarl');
        $this->bill($supplier, 5_000, -2, 'UNAPP-1');

        $scheduler = app(PaymentScheduler::class);
        $run = $scheduler->buildRun(
            new PaymentSchedule($this->company),
            ['scheduled_for' => now()->toDateString(), 'method' => 'bank', 'cash' => 5_000],
            $this->owner,
        );

        // Approval is the control. Without it a payment run is a button that
        // moves money straight out of the bank with nobody's name on it.
        $this->expectException(RuntimeException::class);
        $scheduler->execute($run, $this->owner);
    }
}
