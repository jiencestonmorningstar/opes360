<?php

namespace Tests\Feature\Workflow;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class WorkflowTestCase extends TestCase
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
    }

    protected function memberAt(string $role): User
    {
        $user = User::factory()->create();

        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    /**
     * A workflow with the given steps, in the order given.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<string, mixed>  $attributes
     */
    protected function workflow(array $steps, array $attributes = []): Workflow
    {
        $workflow = Workflow::create(array_merge([
            'name' => 'Expense approval',
            'subject_type' => Expense::class,
            'is_active' => true,
        ], $attributes));

        foreach ($steps as $index => $step) {
            $workflow->steps()->create(array_merge([
                'position' => $index + 1,
                'name' => 'Step '.($index + 1),
                'type' => 'approval',
                'approver_mode' => 'role',
                'approver_role' => Role::MANAGER,
                'quorum' => 'any',
            ], $step));
        }

        return $workflow->fresh();
    }

    /** A subject to run a workflow against. Expenses are the simplest real one. */
    protected function expense(float $amount = 100_000): Expense
    {
        return Expense::create([
            'description' => 'Generator fuel',
            'category' => 'fuel',
            'issue_date' => now()->toDateString(),
            'amount' => $amount,
            'total' => $amount,
            'status' => 'draft',
            'recorded_by' => $this->owner->id,
        ]);
    }
}
