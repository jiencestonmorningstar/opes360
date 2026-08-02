<?php

namespace Tests\Feature;

use App\Livewire\Team\Index as TeamIndex;
use App\Livewire\Team\Show as TeamShow;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmploymentContract;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\SalaryComponent;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The staff file: people, contracts, allowances and leave.
 *
 * The load-bearing idea being tested is that an employee is a record in its own
 * right rather than a user account — most staff in these businesses never log
 * in — and that pay history lives on contracts so it cannot be rewritten by a
 * later raise.
 */
class TeamTest extends TestCase
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
            // On the top plan because the staff file is a Growth-plan module.
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);
    }

    protected function hire(string $first = 'Yvonne', string $last = 'Ngo Bell', float $salary = 200000): Employee
    {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'first_name' => $first, 'last_name' => $last,
            'hired_on' => now()->subYear()->toDateString(),
            'status' => 'active', 'payment_method' => 'cash',
        ]);

        EmploymentContract::create([
            'company_id' => $this->company->id,
            'employee_id' => $employee->id,
            'type' => 'cdi',
            'starts_on' => now()->subYear()->toDateString(),
            'base_salary' => $salary,
            'status' => 'active',
        ]);

        return $employee;
    }

    // ─────────────────────────────────────────────────────────── the file ──

    /** An employee is not a user. Nobody has to be given a login to be paid. */
    public function test_someone_can_be_employed_without_a_login(): void
    {
        $employee = $this->hire();

        $this->assertNull($employee->user_id);
        $this->assertSame('Yvonne Ngo Bell', $employee->name());
    }

    public function test_adding_someone_creates_their_first_contract_too(): void
    {
        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->call('startAdding')
            ->set('firstName', 'Paul')
            ->set('lastName', 'Atangana')
            ->set('jobTitle', 'Chauffeur')
            ->set('hiredOn', now()->toDateString())
            ->set('contractType', 'cdi')
            ->set('baseSalary', '150000')
            ->call('save')
            ->assertHasNoErrors();

        $employee = Employee::query()->firstOrFail();

        $this->assertSame('Paul Atangana', $employee->name());
        $this->assertSame(1, EmploymentContract::query()->where('employee_id', $employee->id)->count());
        $this->assertSame('150000.00', $employee->contracts()->first()->base_salary);
    }

    public function test_a_fixed_term_contract_keeps_its_end_date_and_a_cdi_does_not(): void
    {
        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->call('startAdding')
            ->set('firstName', 'Paul')->set('lastName', 'Atangana')
            ->set('hiredOn', now()->toDateString())
            ->set('contractType', 'cdd')
            ->set('contractEndsOn', now()->addMonths(6)->toDateString())
            ->set('baseSalary', '150000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull(EmploymentContract::query()->firstOrFail()->ends_on);

        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->call('startAdding')
            ->set('firstName', 'Yvonne')->set('lastName', 'Ngo Bell')
            ->set('hiredOn', now()->toDateString())
            ->set('contractType', 'cdi')
            // Even if a date is left over in the form, a permanent contract
            // has no end. A CDD without one would be a CDI with extra steps.
            ->set('contractEndsOn', now()->addMonths(6)->toDateString())
            ->set('baseSalary', '200000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(
            EmploymentContract::query()->where('type', 'cdi')->firstOrFail()->ends_on
        );
    }

    public function test_a_contract_cannot_end_before_it_starts(): void
    {
        Livewire::actingAs($this->owner)
            ->test(TeamIndex::class)
            ->call('startAdding')
            ->set('firstName', 'Paul')->set('lastName', 'Atangana')
            ->set('hiredOn', now()->toDateString())
            ->set('contractType', 'cdd')
            ->set('contractEndsOn', now()->subMonth()->toDateString())
            ->set('baseSalary', '150000')
            ->call('save')
            ->assertHasErrors('contractEndsOn');
    }

    // ──────────────────────────────────────────────────────── contracts ──

    /** A raise is a new contract; the old one closes the day before. */
    public function test_a_new_contract_closes_the_one_it_replaces(): void
    {
        $employee = $this->hire();
        $from = now()->startOfMonth()->toDateString();

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('startContract')
            ->set('contractType', 'cdi')
            ->set('contractStartsOn', $from)
            ->set('contractSalary', '260000')
            ->call('saveContract')
            ->assertHasNoErrors();

        $contracts = EmploymentContract::query()->where('employee_id', $employee->id)->orderBy('starts_on')->get();

        $this->assertCount(2, $contracts);
        $this->assertSame('ended', $contracts[0]->status);
        $this->assertSame(
            now()->startOfMonth()->subDay()->toDateString(),
            $contracts[0]->ended_on->toDateString(),
            'The old contract closes the day before the new one starts, so no month has two.'
        );
        $this->assertSame('active', $contracts[1]->status);
    }

    public function test_only_one_contract_covers_any_given_day(): void
    {
        $employee = $this->hire();

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('startContract')
            ->set('contractStartsOn', now()->startOfMonth()->toDateString())
            ->set('contractSalary', '260000')
            ->call('saveContract');

        $employee->load('contracts');

        $covering = $employee->contracts->filter(fn ($c) => $c->coversDate(now()->toDateString()));

        $this->assertCount(1, $covering);
        $this->assertSame('260000.00', $covering->first()->base_salary);
    }

    public function test_a_contract_below_the_minimum_wage_is_flagged_not_refused(): void
    {
        $employee = $this->hire(salary: 40000);
        $employee->load('contracts');

        $contract = $employee->activeContract();

        $this->assertTrue($contract->isBelowMinimumWage());
        $this->assertSame('active', $contract->status, 'Part-time and apprenticeships legitimately sit below it.');
    }

    // ─────────────────────────────────────────────────── leaving and back ──

    public function test_ending_employment_closes_the_contract_and_keeps_the_record(): void
    {
        $employee = $this->hire();

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('startEnding')
            ->set('endDate', now()->toDateString())
            ->set('endReason', 'Démission')
            ->call('endEmployment')
            ->assertHasNoErrors();

        $employee->refresh()->load('contracts');

        $this->assertSame('ended', $employee->status);
        $this->assertSame('Démission', $employee->end_reason);
        $this->assertSame('ended', $employee->contracts->first()->status);
        $this->assertNotSoftDeleted($employee, ['status' => 'ended']);
    }

    public function test_somebody_can_be_brought_back(): void
    {
        $employee = $this->hire();
        $employee->update(['status' => 'ended', 'ended_on' => now()->toDateString()]);

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('reinstate');

        $this->assertSame('active', $employee->refresh()->status);
        $this->assertNull($employee->ended_on);
    }

    // ────────────────────────────────────────── allowances and deductions ──

    public function test_an_allowance_records_both_exemption_answers_separately(): void
    {
        $employee = $this->hire();

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('startComponent')
            ->set('componentName', 'Prime de transport')
            ->set('componentKind', 'allowance')
            ->set('componentAmount', '25000')
            ->set('componentTaxable', false)
            ->set('componentCnps', false)
            ->call('saveComponent')
            ->assertHasNoErrors();

        $component = SalaryComponent::query()->firstOrFail();

        $this->assertFalse($component->taxable);
        $this->assertFalse($component->cnps_liable);
    }

    /** A deduction is never "taxable"; storing that would mislead a later query. */
    public function test_a_deduction_does_not_carry_exemption_flags(): void
    {
        $employee = $this->hire();

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('startComponent')
            ->set('componentName', 'Remboursement prêt')
            ->set('componentKind', 'deduction')
            ->set('componentAmount', '20000')
            ->call('saveComponent');

        $component = SalaryComponent::query()->firstOrFail();

        $this->assertFalse($component->taxable);
        $this->assertFalse($component->cnps_liable);
    }

    public function test_a_component_can_be_paused_and_resumed(): void
    {
        $employee = $this->hire();

        $component = SalaryComponent::create([
            'company_id' => $this->company->id, 'employee_id' => $employee->id,
            'name' => 'Prime', 'kind' => 'allowance', 'amount' => 10000, 'active' => true,
        ]);

        $screen = Livewire::actingAs($this->owner)->test(TeamShow::class, ['employee' => $employee]);

        $screen->call('toggleComponent', $component->id);
        $this->assertFalse($component->refresh()->active);

        $screen->call('toggleComponent', $component->id);
        $this->assertTrue($component->refresh()->active);
    }

    // ─────────────────────────────────────────────────────────────── leave ──

    /** Weekends are skipped; the count is a suggestion the approver can edit. */
    public function test_the_working_day_count_skips_weekends(): void
    {
        // A Monday to the Friday of the following week: ten working days.
        $this->assertSame(10.0, LeaveRequest::workingDaysBetween('2026-08-03', '2026-08-14'));
        // A single Saturday is no working days at all.
        $this->assertSame(0.0, LeaveRequest::workingDaysBetween('2026-08-08', '2026-08-08'));
    }

    public function test_leave_is_requested_then_decided(): void
    {
        $employee = $this->hire();

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('startLeave')
            ->set('leaveType', 'annual')
            ->set('leaveFrom', '2026-08-03')
            ->set('leaveTo', '2026-08-07')
            ->call('saveLeave')
            ->assertHasNoErrors();

        $leave = LeaveRequest::query()->firstOrFail();

        $this->assertSame('pending', $leave->status);
        $this->assertSame('5.00', $leave->days);
        $this->assertTrue($leave->paid);
        $this->assertTrue($leave->deducts_balance);

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('decideLeave', $leave->id, 'approve');

        $this->assertSame('approved', $leave->refresh()->status);
        $this->assertNotNull($leave->decided_at);
    }

    public function test_unpaid_leave_neither_pays_nor_touches_the_balance(): void
    {
        $employee = $this->hire();

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('startLeave')
            ->set('leaveType', 'unpaid')
            ->set('leaveFrom', '2026-08-03')
            ->set('leaveTo', '2026-08-07')
            ->call('saveLeave');

        $leave = LeaveRequest::query()->firstOrFail();

        $this->assertFalse($leave->paid);
        $this->assertFalse($leave->deducts_balance);
    }

    public function test_leave_cannot_end_before_it_starts(): void
    {
        $employee = $this->hire();

        Livewire::actingAs($this->owner)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('startLeave')
            ->set('leaveFrom', '2026-08-10')
            ->set('leaveTo', '2026-08-03')
            ->set('leaveDays', '3')
            ->call('saveLeave')
            ->assertHasErrors('leaveTo');
    }

    /**
     * A day and a half a month, plus whatever was carried in. A business that
     * has been running for years cannot have its staff start from zero because
     * the software is new.
     */
    public function test_the_leave_balance_accrues_from_the_hire_date(): void
    {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Yvonne', 'last_name' => 'Ngo Bell',
            'hired_on' => now()->subMonths(12)->toDateString(),
            'leave_opening_balance' => 4,
            'status' => 'active',
        ]);

        $employee->load('leaveRequests');

        $this->assertSame(22.0, $employee->leaveBalance(), '4 carried in plus 12 × 1.5.');
    }

    public function test_approved_leave_comes_off_the_balance_and_a_declined_request_does_not(): void
    {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Yvonne', 'last_name' => 'Ngo Bell',
            'hired_on' => now()->subMonths(12)->toDateString(),
            'status' => 'active',
        ]);

        foreach ([['approved', 5], ['declined', 4], ['pending', 3]] as [$status, $days]) {
            LeaveRequest::create([
                'company_id' => $this->company->id, 'employee_id' => $employee->id,
                'type' => 'annual', 'starts_on' => now()->toDateString(), 'ends_on' => now()->toDateString(),
                'days' => $days, 'status' => $status, 'deducts_balance' => true,
            ]);
        }

        $employee->load('leaveRequests');

        $this->assertSame(13.0, $employee->leaveBalance(), '18 accrued less the 5 actually approved.');
    }

    // ─────────────────────────────────────────────────── who may do what ──

    public function test_a_manager_keeps_the_file_and_decides_leave(): void
    {
        $manager = User::factory()->create();
        $this->joinCompany($this->company, $manager, 'manager');

        $this->actingAs($manager)->get(route('team'))->assertOk();

        $employee = $this->hire();

        Livewire::actingAs($manager)
            ->test(TeamShow::class, ['employee' => $employee])
            ->call('startLeave')
            ->set('leaveFrom', '2026-08-03')
            ->set('leaveTo', '2026-08-04')
            ->call('saveLeave')
            ->assertHasNoErrors();
    }

    /**
     * Read Only means an auditor, and "everything" stops short of what each
     * colleague earns.
     */
    public function test_a_read_only_user_cannot_see_the_staff_file(): void
    {
        $auditor = User::factory()->create();
        $this->joinCompany($this->company, $auditor, 'read-only');

        $this->actingAs($auditor)->get(route('team'))->assertForbidden();
    }

    public function test_the_team_belongs_to_its_company_alone(): void
    {
        $this->hire();

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl', 'owner_id' => $otherOwner->id,
            'currency' => 'XAF', 'plan' => 'basic', 'account_type' => 'active',
        ]);

        $this->joinCompany($other, $otherOwner, Role::OWNER);
        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, Employee::query()->count());
        $this->assertSame(0, EmploymentContract::query()->count());
    }

    public function test_the_team_screen_renders(): void
    {
        $this->hire();

        $this->actingAs($this->owner)->get(route('team'))->assertOk()->assertSee('Yvonne');
        $this->actingAs($this->owner)->get(route('team.show', Employee::query()->firstOrFail()))->assertOk();
    }
}
