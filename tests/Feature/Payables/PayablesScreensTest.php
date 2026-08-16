<?php

namespace Tests\Feature\Payables;

use App\Livewire\Payables\Reconcile;
use App\Livewire\Payables\Runs;
use App\Livewire\Payables\Schedule;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\PaymentRun;
use App\Models\Role;
use App\Models\User;
use App\Services\Payables\PaymentScheduler;
use App\Services\Payables\SupplierReconciler;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use App\Support\PaymentSchedule;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The payables screens.
 *
 * What is tested here is mostly what the screens refuse to do. The reserve has
 * to actually hold money back, an unapproved run has to stay unpaid however it
 * is poked, and somebody who builds runs must not find an approve button on the
 * one they just built — that separation is the whole control.
 */
class PayablesScreensTest extends TestCase
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
            // Payment scheduling ships off — a shop that pays its supplier when
            // the supplier turns up has nothing to schedule.
            'modules' => ['payables' => true],
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);
    }

    protected function supplier(string $name = 'Ciment du Cameroun'): Contact
    {
        return Contact::create(['name' => $name, 'type' => 'supplier', 'balance' => 0]);
    }

    protected function bill(Contact $supplier, float $total, int $dueInDays, string $reference): Expense
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
            'amount_paid' => 0,
        ]);

        $expense->recompute();
        $expense->save();

        return $expense;
    }

    /** Somebody who may build a run but not sign it off. */
    protected function accountant(): User
    {
        $user = User::factory()->create();

        $this->joinCompany($this->company, $user, Role::ACCOUNTANT);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    public function test_the_schedule_may_not_spend_the_reserve(): void
    {
        $supplier = $this->supplier('Reserve Sarl');
        $this->bill($supplier, 100_000, -30, 'RES-1');

        $plan = Livewire::actingAs($this->owner)
            ->test(Schedule::class)
            ->set('cash', '100000')
            ->set('reserve', '70000')
            ->viewData('plan');

        // 100,000 in the bank with 70,000 spoken for is 30,000 to pay bills
        // with, whatever the supplier is owed.
        $this->assertSame(30_000.0, $plan['cash_available']);
        $this->assertSame(30_000.0, $plan['total_scheduled']);
        $this->assertSame(70_000.0, $plan['shortfall']);
        $this->assertSame(PaymentSchedule::DECISION_PART, $plan['items'][0]['decision']);
    }

    public function test_dropping_the_reserve_releases_the_money_it_was_holding(): void
    {
        $supplier = $this->supplier('Release Sarl');
        $this->bill($supplier, 100_000, -30, 'REL-1');

        $component = Livewire::actingAs($this->owner)
            ->test(Schedule::class)
            ->set('cash', '100000')
            ->set('reserve', '70000');

        $this->assertSame(30_000.0, $component->viewData('plan')['total_scheduled']);

        $component->set('reserve', '0');

        $this->assertSame(100_000.0, $component->viewData('plan')['total_scheduled']);
        $this->assertSame(PaymentSchedule::DECISION_FUND, $component->viewData('plan')['items'][0]['decision']);
    }

    public function test_an_unapproved_run_cannot_be_paid_from_the_screen(): void
    {
        $supplier = $this->supplier('Unapproved Sarl');
        $bill = $this->bill($supplier, 50_000, -10, 'UNAPP-1');

        $run = app(PaymentScheduler::class)->buildRun(
            new PaymentSchedule($this->company, null, 0.0),
            ['cash' => 50_000],
            $this->owner,
        );

        Livewire::actingAs($this->owner)
            ->test(Runs::class)
            ->set('runId', $run->id)
            ->call('execute')
            // The service's refusal is put in front of the person, not swallowed.
            ->assertSee('A payment run must be approved before it can be paid.');

        // And no money moved.
        $this->assertSame(PaymentRun::STATUS_DRAFT, $run->fresh()->status);
        $this->assertSame(0.0, round((float) $bill->fresh()->amount_paid, 2));
    }

    public function test_the_release_step_only_appears_once_a_run_is_approved(): void
    {
        $supplier = $this->supplier('Approved Sarl');
        $this->bill($supplier, 50_000, -10, 'APP-1');

        $scheduler = app(PaymentScheduler::class);
        $run = $scheduler->buildRun(new PaymentSchedule($this->company, null, 0.0), ['cash' => 50_000], $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Runs::class)
            ->set('runId', $run->id)
            ->assertSee('Approve this run')
            ->assertDontSee('Release the money');

        $scheduler->approve($run->fresh(), $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Runs::class)
            ->set('runId', $run->id)
            ->assertSee('Release the money')
            ->assertDontSee('Approve this run');
    }

    public function test_someone_who_builds_runs_does_not_get_an_approve_control(): void
    {
        $supplier = $this->supplier('Segregation Sarl');
        $this->bill($supplier, 50_000, -10, 'SEG-1');

        $accountant = $this->accountant();

        $run = app(PaymentScheduler::class)->buildRun(
            new PaymentSchedule($this->company, null, 0.0),
            ['cash' => 50_000],
            $accountant,
        );

        Livewire::actingAs($accountant)
            ->test(Runs::class)
            ->set('runId', $run->id)
            ->assertSee('Add a bill the plan missed')
            ->assertDontSee('Approve this run')
            ->assertSee('waiting on somebody who may approve');

        // And not merely hidden: the action itself refuses.
        Livewire::actingAs($accountant)
            ->test(Runs::class)
            ->set('runId', $run->id)
            ->call('approve')
            ->assertForbidden();

        $this->assertSame(PaymentRun::STATUS_DRAFT, $run->fresh()->status);
    }

    public function test_the_reconciliation_reports_a_difference_in_both_directions(): void
    {
        $supplier = $this->supplier('Disagree Sarl');

        // One bill of ours they have not billed, and one charge of theirs we
        // have never recorded. Both explain part of the gap, in opposite ways.
        $this->bill($supplier, 200_000, -20, 'OURS-1');

        $statement = app(SupplierReconciler::class)->import($supplier, [
            'statement_date' => now()->toDateString(),
            'closing_balance' => 300_000,
        ], [
            ['line_date' => now()->subDays(5)->toDateString(), 'reference' => 'THEIRS-1', 'description' => 'Livraison', 'amount' => 300_000],
        ], $this->owner);

        $component = Livewire::actingAs($this->owner)
            ->test(Reconcile::class)
            ->set('supplierId', $supplier->id)
            ->set('statementId', $statement->id);

        $summary = $component->viewData('summary');

        $this->assertSame(300_000.0, $summary['their_balance']);
        $this->assertSame(200_000.0, $summary['our_balance']);
        $this->assertSame(100_000.0, $summary['difference']);
        $this->assertSame('THEIRS-1', $summary['on_their_statement_only'][0]['reference']);
        $this->assertSame('OURS-1', $summary['in_our_books_only'][0]['reference']);

        $component
            ->assertSee('On their statement only')
            ->assertSee('In your books only')
            ->assertSee('They claim more than you have recorded');
    }

    public function test_a_supplier_whose_own_lines_do_not_add_up_is_a_finding_not_a_correction(): void
    {
        $supplier = $this->supplier('Arithmetic Sarl');

        $statement = app(SupplierReconciler::class)->import($supplier, [
            'statement_date' => now()->toDateString(),
            // They claim 500,000 while their own lines say 100,000.
            'closing_balance' => 500_000,
        ], [
            ['line_date' => now()->subDays(3)->toDateString(), 'reference' => 'X-1', 'amount' => 100_000],
        ], $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Reconcile::class)
            ->set('supplierId', $supplier->id)
            ->set('statementId', $statement->id)
            ->assertSee('That is their arithmetic, not yours');

        // Left exactly as they sent it.
        $this->assertSame(500_000.0, round((float) $statement->fresh()->closing_balance, 2));
    }
}
