<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\User;
use App\Support\PlanEntitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCompany(array $overrides = []): Company
    {
        $owner = User::factory()->create();

        return Company::create(array_merge([
            'slug' => 'acme-'.uniqid(),
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ], $overrides));
    }

    public function test_basic_plan_active_company_is_denied_forms_and_events(): void
    {
        $company = $this->makeCompany(['plan' => 'basic', 'account_type' => 'active']);

        $this->assertFalse(PlanEntitlements::allows($company, 'forms'));
        $this->assertFalse(PlanEntitlements::allows($company, 'events'));
        $this->assertFalse(PlanEntitlements::allows($company, 'loyalty'));
    }

    public function test_growth_plan_active_company_gets_forms_but_not_events(): void
    {
        $company = $this->makeCompany(['plan' => 'growth', 'account_type' => 'active']);

        $this->assertTrue(PlanEntitlements::allows($company, 'papers'));
        $this->assertTrue(PlanEntitlements::allows($company, 'forms'));
        $this->assertFalse(PlanEntitlements::allows($company, 'events'));
        $this->assertFalse(PlanEntitlements::allows($company, 'loyalty'));
    }

    public function test_business_plan_active_company_gets_everything(): void
    {
        $company = $this->makeCompany(['plan' => 'business', 'account_type' => 'active']);

        $this->assertTrue(PlanEntitlements::allows($company, 'papers'));
        $this->assertTrue(PlanEntitlements::allows($company, 'forms'));
        $this->assertTrue(PlanEntitlements::allows($company, 'events'));
        $this->assertTrue(PlanEntitlements::allows($company, 'loyalty'));
    }

    public function test_demo_and_trial_accounts_are_exempt_regardless_of_plan(): void
    {
        $demo = $this->makeCompany(['plan' => 'basic', 'account_type' => 'demo']);
        $trial = $this->makeCompany(['plan' => 'basic', 'account_type' => 'trial']);

        $this->assertTrue(PlanEntitlements::allows($demo, 'events'));
        $this->assertTrue(PlanEntitlements::allows($trial, 'loyalty'));
    }

    public function test_modules_outside_the_map_are_always_allowed(): void
    {
        $company = $this->makeCompany(['plan' => 'basic', 'account_type' => 'active']);

        $this->assertTrue(PlanEntitlements::allows($company, 'sales'));
        $this->assertTrue(PlanEntitlements::allowsAbility($company, 'sales.create'));
    }

    public function test_allows_ability_reads_the_module_from_the_dotted_ability(): void
    {
        $company = $this->makeCompany(['plan' => 'basic', 'account_type' => 'active']);

        $this->assertFalse(PlanEntitlements::allowsAbility($company, 'events.create'));
        $this->assertTrue(PlanEntitlements::allowsAbility($company, 'sales.view'));
    }
}
