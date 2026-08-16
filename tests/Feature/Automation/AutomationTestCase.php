<?php

namespace Tests\Feature\Automation;

use App\Models\AutomationRule;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class AutomationTestCase extends TestCase
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

    /** @param  array<string, mixed>  $attributes */
    protected function rule(array $attributes = []): AutomationRule
    {
        return AutomationRule::create(array_merge([
            'name' => 'Flag big spending',
            'event' => 'expense.recorded',
            'action' => 'set_field',
            'action_config' => ['field' => 'kind', 'value' => 'flagged'],
            'is_active' => true,
        ], $attributes));
    }
}
