<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\CostCentre;
use App\Models\Department;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FiscalPeriods;
use App\Services\Accounting\Ledger;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class FiscalPeriodTest extends TestCase
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

    // ── Years and periods ────────────────────────────────────────────────

    public function test_a_year_is_created_with_twelve_months(): void
    {
        $year = $this->createYear();

        $this->assertSame(12, $year->periods()->count());
    }

    /** A financial year need not start in January. */
    public function test_a_year_can_start_in_any_month(): void
    {
        $year = $this->createYear('FY 2026/27', '2026-07-01');

        $this->assertSame('2026-07-01', $year->starts_on->toDateString());
        $this->assertSame('2027-06-30', $year->ends_on->toDateString());
        $this->assertSame('July 2026', $year->periods()->first()->name);
    }

    public function test_the_period_covering_a_date_is_found(): void
    {
        $this->createYear();

        $period = $this->periods()->periodFor($this->company, new \DateTimeImmutable('2026-03-15'));

        $this->assertNotNull($period);
        $this->assertSame('March 2026', $period->name);
    }

    // ── Posting ──────────────────────────────────────────────────────────

    /** The feature is opt-in: a business with no periods is unrestricted. */
    public function test_a_business_with_no_periods_can_post_to_any_date(): void
    {
        $this->assertTrue($this->periods()->isPostingAllowed($this->company, new \DateTimeImmutable('2026-03-15')));
    }

    public function test_an_open_period_accepts_postings(): void
    {
        $this->createYear();

        $this->assertTrue($this->periods()->isPostingAllowed($this->company, new \DateTimeImmutable('2026-03-15')));
    }

    public function test_a_closed_period_refuses_postings(): void
    {
        $year = $this->createYear();
        $march = $year->periods()->where('name', 'March 2026')->first();

        $this->periods()->closePeriod($march, $this->owner);

        $this->assertFalse($this->periods()->isPostingAllowed($this->company, new \DateTimeImmutable('2026-03-15')));
    }

    public function test_closing_one_period_leaves_the_others_open(): void
    {
        $year = $this->createYear();
        $this->periods()->closePeriod($year->periods()->where('name', 'March 2026')->first(), $this->owner);

        $this->assertTrue($this->periods()->isPostingAllowed($this->company, new \DateTimeImmutable('2026-04-15')));
    }

    /** The rule has to hold at the one place everything reaches the books. */
    public function test_the_ledger_itself_refuses_to_post_into_a_closed_period(): void
    {
        $year = $this->createYear();
        $this->periods()->closePeriod($year->periods()->where('name', 'March 2026')->first(), $this->owner);

        $this->expectException(RuntimeException::class);

        app(Ledger::class)->post($this->company, 'OD', '2026-03-15', [
            ['account' => 'cash', 'debit' => 100],
            ['account' => 'sales_goods', 'credit' => 100],
        ]);
    }

    public function test_the_ledger_still_posts_into_an_open_period(): void
    {
        $this->createYear();

        $entry = app(Ledger::class)->post($this->company, 'OD', '2026-04-15', [
            ['account' => 'cash', 'debit' => 100],
            ['account' => 'sales_goods', 'credit' => 100],
        ]);

        $this->assertNotNull($entry->id);
    }

    // ── Closing and reopening ────────────────────────────────────────────

    public function test_closing_records_who_and_when(): void
    {
        $year = $this->createYear();
        $march = $year->periods()->where('name', 'March 2026')->first();

        $closed = $this->periods()->closePeriod($march, $this->owner);

        $this->assertSame($this->owner->id, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);
    }

    public function test_a_period_cannot_be_closed_twice(): void
    {
        $year = $this->createYear();
        $march = $year->periods()->where('name', 'March 2026')->first();
        $this->periods()->closePeriod($march, $this->owner);

        $this->expectException(RuntimeException::class);

        $this->periods()->closePeriod($march->fresh(), $this->owner);
    }

    /** A period closed by mistake must be recoverable, or nobody will close anything. */
    public function test_a_period_can_be_reopened(): void
    {
        $year = $this->createYear();
        $march = $year->periods()->where('name', 'March 2026')->first();
        $this->periods()->closePeriod($march, $this->owner);

        $this->periods()->reopenPeriod($march->fresh());

        $this->assertTrue($this->periods()->isPostingAllowed($this->company, new \DateTimeImmutable('2026-03-15')));
    }

    public function test_closing_a_year_closes_every_period_in_it(): void
    {
        $year = $this->createYear();

        $this->periods()->closeYear($year, $this->owner);

        $this->assertSame(0, $year->fresh()->periods()->where('status', 'open')->count());
    }

    public function test_a_closed_year_refuses_postings_even_to_a_period_marked_open(): void
    {
        $year = $this->createYear();
        $march = $year->periods()->where('name', 'March 2026')->first();
        $this->periods()->closeYear($year, $this->owner);
        // Force the period open behind the year's back.
        $march->update(['status' => 'open']);

        $this->assertFalse($this->periods()->isPostingAllowed($this->company, new \DateTimeImmutable('2026-03-15')));
    }

    public function test_a_period_inside_a_closed_year_cannot_be_reopened_alone(): void
    {
        $year = $this->createYear();
        $march = $year->periods()->where('name', 'March 2026')->first();
        $this->periods()->closeYear($year, $this->owner);

        $this->expectException(RuntimeException::class);

        $this->periods()->reopenPeriod($march->fresh());
    }

    /** Reopening a year is permission to reopen a month, not a decision that every month reopens. */
    public function test_reopening_a_year_leaves_its_periods_closed(): void
    {
        $year = $this->createYear();
        $this->periods()->closeYear($year, $this->owner);

        $this->periods()->reopenYear($year->fresh());

        $this->assertSame(12, $year->fresh()->periods()->where('status', 'closed')->count());
    }

    // ── Cost centres ─────────────────────────────────────────────────────

    public function test_a_cost_centre_can_be_created_and_linked_to_a_department(): void
    {
        $department = Department::create(['name' => 'Operations']);
        $centre = CostCentre::create([
            'code' => 'OPS-01', 'name' => 'Operations budget',
            'department_id' => $department->id, 'is_active' => true,
        ]);

        $this->assertTrue($centre->department->is($department));
    }

    public function test_a_cost_centre_is_independent_of_departments(): void
    {
        $centre = CostCentre::create(['code' => 'GEN', 'name' => 'General', 'is_active' => true]);

        $this->assertNull($centre->department_id);
    }

    protected function createYear(string $name = '2026', string $startsOn = '2026-01-01'): FiscalYear
    {
        return $this->periods()->createYearWithMonths(
            $this->company,
            $name,
            CarbonImmutable::parse($startsOn),
            $this->owner,
        );
    }

    protected function periods(): FiscalPeriods
    {
        return app(FiscalPeriods::class);
    }
}
