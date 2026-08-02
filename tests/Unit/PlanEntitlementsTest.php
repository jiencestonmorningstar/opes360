<?php

namespace Tests\Unit;

use App\Models\Company;
use App\Models\User;
use App\Support\Permissions;
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

    // ─────────────────────────────────────────── the books and the payroll ──

    /** What a shopkeeper gets for 3 000 F: sell, buy, count stock, keep books. */
    public function test_basic_includes_the_trading_modules(): void
    {
        $company = $this->makeCompany(['plan' => 'basic', 'account_type' => 'active']);

        $this->assertTrue(PlanEntitlements::allowsAbility($company, 'expenses.create'));
        $this->assertTrue(PlanEntitlements::allowsAbility($company, 'accounting.view'));
        // Stock locations sit under Products, so they follow it rather than
        // being gated on their own.
        $this->assertTrue(PlanEntitlements::allowsAbility($company, 'products.manage-locations'));
    }

    public function test_basic_is_denied_the_accountants_modules_and_the_payroll(): void
    {
        $company = $this->makeCompany(['plan' => 'basic', 'account_type' => 'active']);

        $this->assertFalse(PlanEntitlements::allowsAbility($company, 'assets.view'));
        $this->assertFalse(PlanEntitlements::allowsAbility($company, 'banking.view'));
        $this->assertFalse(PlanEntitlements::allowsAbility($company, 'employees.view'));
        $this->assertFalse(PlanEntitlements::allowsAbility($company, 'leave.view'));
        $this->assertFalse(PlanEntitlements::allowsAbility($company, 'payroll.view'));
    }

    public function test_growth_gets_the_books_and_the_staff_file_but_not_the_payroll(): void
    {
        $company = $this->makeCompany(['plan' => 'growth', 'account_type' => 'active']);

        $this->assertTrue(PlanEntitlements::allowsAbility($company, 'assets.create'));
        $this->assertTrue(PlanEntitlements::allowsAbility($company, 'banking.reconcile'));
        $this->assertTrue(PlanEntitlements::allowsAbility($company, 'employees.create'));
        $this->assertTrue(PlanEntitlements::allowsAbility($company, 'leave.approve'));
        $this->assertFalse(PlanEntitlements::allowsAbility($company, 'payroll.run'));
    }

    public function test_business_gets_everything(): void
    {
        $company = $this->makeCompany(['plan' => 'business', 'account_type' => 'active']);

        foreach (Permissions::slugs() as $ability) {
            $this->assertTrue(
                PlanEntitlements::allowsAbility($company, $ability),
                "The top plan should include [{$ability}]."
            );
        }
    }

    /**
     * The pricing page's module table is what a customer read before paying,
     * so it is the specification and PlanEntitlements is the enforcement. This
     * asserts the two say the same thing — the comment in the pricing view
     * promises they "can never quietly drift apart", and nothing was checking.
     */
    public function test_the_pricing_page_and_the_entitlements_agree(): void
    {
        $rows = $this->pricingModuleAccess();

        $this->assertNotEmpty($rows, 'The pricing page has no module table to compare against.');

        // caption on the pricing page => the ability it stands for
        $abilities = [
            'Purchases & expenses' => 'expenses.view',
            'SYSCOHADA accounting' => 'accounting.view',
            'Inventory & stock locations' => 'products.manage-locations',
            'Fixed assets & depreciation' => 'assets.view',
            'Bank reconciliation' => 'banking.view',
            'Team & HR records, contracts, leave' => 'employees.view',
            'Payroll (CNPS, IRPP, payslips, register)' => 'payroll.view',
            'Opes Events (ticketing & QR check-in)' => 'events.view',
            'Loyalty program & printed cards' => 'loyalty.view',
        ];

        foreach ($abilities as $caption => $ability) {
            $this->assertArrayHasKey($caption, $rows, "The pricing table no longer lists [{$caption}].");

            foreach (['basic', 'growth', 'business'] as $plan) {
                $company = $this->makeCompany(['plan' => $plan, 'account_type' => 'active']);

                $this->assertSame(
                    $rows[$caption][$plan],
                    PlanEntitlements::allowsAbility($company, $ability),
                    "The pricing page and the code disagree about [{$caption}] on the {$plan} plan."
                );
            }
        }
    }

    /**
     * Reads the `$moduleAccess` array straight out of the pricing view. Parsing
     * a Blade file in a test is ugly; letting the page promise one thing while
     * the software does another is worse.
     *
     * @return array<string, array{basic: bool, growth: bool, business: bool}>
     */
    protected function pricingModuleAccess(): array
    {
        $source = file_get_contents(resource_path('views/marketing/pricing.blade.php'));

        preg_match_all(
            "/\['module' => '((?:[^'\\\\]|\\\\.)*)',\s*'basic' => (true|false),\s*'growth' => (true|false),\s*'business' => (true|false)\]/",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $rows = [];

        foreach ($matches as $match) {
            $rows[str_replace("\\'", "'", $match[1])] = [
                'basic' => $match[2] === 'true',
                'growth' => $match[3] === 'true',
                'business' => $match[4] === 'true',
            ];
        }

        return $rows;
    }
}
