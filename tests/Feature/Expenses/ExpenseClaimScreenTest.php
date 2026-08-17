<?php

namespace Tests\Feature\Expenses;

use App\Livewire\Expenses\Claims;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ExpenseClaim;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Services\ExpenseClaimService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The claims screen — the UI over ExpenseClaimService. The bookkeeping is
 * covered in ExpenseClaimsTest; this file only proves the screen can drive
 * the service and that the gates on it hold.
 */
class ExpenseClaimScreenTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

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

    protected function memberAt(string $role): User
    {
        $user = User::factory()->create();

        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    protected function employee(): Employee
    {
        return Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Awa',
            'last_name' => 'Ngo',
            'status' => 'active',
            'hired_on' => now()->subYear()->toDateString(),
        ]);
    }

    protected function claim(): ExpenseClaim
    {
        return app(ExpenseClaimService::class)->create([
            'employee_id' => $this->employee()->id,
            'title' => 'Site visit, Douala',
            'claim_date' => now()->toDateString(),
            'lines' => [
                ['description' => 'Taxi', 'category' => 'transport', 'amount' => 12_000],
            ],
        ], $this->owner);
    }

    protected function approvalWorkflow(): Workflow
    {
        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Expense claim approval',
            'subject_type' => ExpenseClaim::class,
            'is_active' => true,
            'is_default' => true,
        ]);

        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Manager',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
        ]);

        return $workflow->fresh();
    }

    // ── The route ────────────────────────────────────────────────────────

    public function test_the_route_opens_for_the_owner(): void
    {
        $this->actingAs($this->owner);

        $this->get(route('expenses.claims'))->assertOk();
    }

    public function test_the_route_is_closed_to_a_cashier(): void
    {
        $this->actingAs($this->memberAt(Role::CASHIER));

        $this->get(route('expenses.claims'))->assertForbidden();
    }

    // ── The screen ───────────────────────────────────────────────────────

    public function test_the_screen_lists_claims(): void
    {
        $this->actingAs($this->owner);
        $this->claim();

        Livewire::test(Claims::class)->assertSee('Site visit, Douala');
    }

    public function test_a_claim_can_be_created_with_lines(): void
    {
        $this->actingAs($this->owner);
        $employee = $this->employee();

        Livewire::test(Claims::class)
            ->set('creating', true)
            ->set('employeeId', $employee->id)
            ->set('title', 'Fuel run')
            ->set('claimDate', now()->toDateString())
            ->set('lines.0.description', 'Diesel')
            ->set('lines.0.category', 'fuel')
            ->set('lines.0.amount', '20000')
            ->call('save')
            ->assertHasNoErrors();

        $claim = ExpenseClaim::query()->firstWhere('title', 'Fuel run');

        $this->assertNotNull($claim);
        $this->assertSame('draft', $claim->status);
        $this->assertSame('20000.00', $claim->total);
    }

    public function test_a_claim_needs_at_least_one_filled_line(): void
    {
        $this->actingAs($this->owner);
        $employee = $this->employee();

        Livewire::test(Claims::class)
            ->set('creating', true)
            ->set('employeeId', $employee->id)
            ->set('title', 'Empty claim')
            ->set('claimDate', now()->toDateString())
            ->call('save')
            ->assertHasErrors();

        $this->assertDatabaseMissing('expense_claims', ['title' => 'Empty claim']);
    }

    public function test_submitting_hands_the_claim_to_the_engine(): void
    {
        $this->actingAs($this->owner);
        $this->memberAt(Role::MANAGER);
        $this->approvalWorkflow();
        $claim = $this->claim();

        Livewire::test(Claims::class)
            ->call('submit', $claim->id)
            ->assertHasNoErrors();

        $this->assertSame('submitted', $claim->fresh()->status);
    }

    public function test_submitting_without_a_workflow_surfaces_the_refusal(): void
    {
        $this->actingAs($this->owner);
        $claim = $this->claim();

        Livewire::test(Claims::class)
            ->call('submit', $claim->id)
            ->assertHasErrors('claims');

        $this->assertSame('draft', $claim->fresh()->status);
    }

    public function test_an_approved_claim_can_be_reimbursed_from_the_screen(): void
    {
        $this->actingAs($this->owner);
        $manager = $this->memberAt(Role::MANAGER);
        $workflow = $this->approvalWorkflow();
        $claim = $this->claim();

        $service = app(ExpenseClaimService::class);
        $instance = $service->submit($claim, $workflow, $this->owner);
        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        Livewire::test(Claims::class)
            ->call('startReimburse', $claim->id)
            ->set('payMethod', 'cash')
            ->set('payAmount', '12000')
            ->call('reimburse')
            ->assertHasNoErrors();

        $this->assertSame('reimbursed', $claim->fresh()->status);
    }

    public function test_a_manager_cannot_reimburse(): void
    {
        $this->actingAs($this->owner);
        $claim = $this->claim();

        $this->actingAs($this->memberAt(Role::MANAGER));

        Livewire::test(Claims::class)
            ->call('startReimburse', $claim->id)
            ->assertForbidden();
    }

    public function test_overpaying_is_refused_with_an_error_not_a_crash(): void
    {
        $this->actingAs($this->owner);
        $manager = $this->memberAt(Role::MANAGER);
        $workflow = $this->approvalWorkflow();
        $claim = $this->claim();

        $service = app(ExpenseClaimService::class);
        $instance = $service->submit($claim, $workflow, $this->owner);
        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        Livewire::test(Claims::class)
            ->call('startReimburse', $claim->id)
            ->set('payMethod', 'cash')
            ->set('payAmount', '999999')
            ->call('reimburse')
            ->assertHasErrors('claims');
    }
}
