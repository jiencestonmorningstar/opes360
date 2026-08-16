<?php

namespace Tests\Feature\Compliance;

use App\Models\Company;
use App\Models\ComplianceFiling;
use App\Models\ComplianceObligation;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class ComplianceTestCase extends TestCase
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

    /** The everyday case: a quarterly VAT return, due at the end of next month. */
    protected function obligation(array $overrides = []): ComplianceObligation
    {
        return ComplianceObligation::create(array_merge([
            'name' => 'TVA declaration',
            'category' => 'tax',
            'authority' => 'DGI',
            'interval_months' => 3,
            'schedule_basis' => 'due',
            'next_due_on' => now()->addMonth()->toDateString(),
            'lead_days' => 14,
            'owner_id' => $this->owner->id,
            'created_by' => $this->owner->id,
        ], $overrides));
    }

    /** A sign-off path for filings, so the engine has something to run. */
    protected function signOffWorkflow(): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Statutory filing sign-off',
            'subject_type' => ComplianceFiling::class,
            'is_active' => true,
            'is_default' => true,
        ]);

        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Finance manager',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
        ]);

        return $workflow->fresh();
    }
}
