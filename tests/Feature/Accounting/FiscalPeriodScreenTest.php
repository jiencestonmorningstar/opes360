<?php

namespace Tests\Feature\Accounting;

use App\Livewire\Accounting\Index;
use App\Models\Company;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\FiscalPeriods;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The periods tab on the accounting screen — the first UI over
 * FiscalPeriods::close/reopen, which until now had no caller at all.
 */
class FiscalPeriodScreenTest extends TestCase
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

    protected function year(): FiscalYear
    {
        return app(FiscalPeriods::class)->createYearWithMonths(
            $this->company, 'FY 2026', CarbonImmutable::parse('2026-01-01'), $this->owner,
        );
    }

    public function test_the_periods_tab_lists_the_years_and_their_months(): void
    {
        $this->actingAs($this->owner);
        $this->year();

        Livewire::test(Index::class)
            ->set('tab', 'periods')
            ->assertSee('FY 2026')
            ->assertSee('January 2026')
            ->assertSee('December 2026');
    }

    public function test_a_year_can_be_created_from_the_screen(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Index::class)
            ->set('tab', 'periods')
            ->set('yearName', 'FY 2027')
            ->set('yearStartsOn', '2027-01-01')
            ->call('createYear')
            ->assertHasNoErrors();

        $year = FiscalYear::query()->firstWhere('name', 'FY 2027');
        $this->assertNotNull($year);
        $this->assertSame(12, $year->periods()->count());
    }

    public function test_a_period_can_be_closed_and_reopened(): void
    {
        $this->actingAs($this->owner);
        $year = $this->year();
        $period = $year->periods()->first();

        $component = Livewire::test(Index::class)
            ->set('tab', 'periods')
            ->call('closePeriod', $period->id)
            ->assertHasNoErrors();

        $this->assertTrue($period->fresh()->isClosed());

        $component->call('reopenPeriod', $period->id)->assertHasNoErrors();

        $this->assertFalse($period->fresh()->isClosed());
    }

    public function test_closing_a_year_closes_every_period_in_it(): void
    {
        $this->actingAs($this->owner);
        $year = $this->year();

        Livewire::test(Index::class)
            ->set('tab', 'periods')
            ->call('closeYear', $year->id)
            ->assertHasNoErrors();

        $this->assertTrue($year->fresh()->isClosed());
        $this->assertSame(0, $year->periods()->open()->count());
    }

    public function test_a_period_inside_a_closed_year_cannot_be_reopened(): void
    {
        $this->actingAs($this->owner);
        $year = $this->year();
        app(FiscalPeriods::class)->closeYear($year, $this->owner);

        Livewire::test(Index::class)
            ->set('tab', 'periods')
            ->call('reopenPeriod', $year->periods()->first()->id)
            ->assertHasErrors('periods');

        $this->assertTrue($year->periods()->first()->fresh()->isClosed());
    }

    public function test_reopening_a_year_leaves_its_periods_closed(): void
    {
        $this->actingAs($this->owner);
        $year = $this->year();
        app(FiscalPeriods::class)->closeYear($year, $this->owner);

        Livewire::test(Index::class)
            ->set('tab', 'periods')
            ->call('reopenYear', $year->id)
            ->assertHasNoErrors();

        $this->assertFalse($year->fresh()->isClosed());
        $this->assertSame(12, $year->periods()->where('status', 'closed')->count());
    }

    public function test_closing_is_refused_to_a_manager(): void
    {
        $year = $this->year();

        $this->actingAs($this->memberAt(Role::MANAGER));

        Livewire::test(Index::class)
            ->set('tab', 'periods')
            ->call('closePeriod', $year->periods()->first()->id)
            ->assertForbidden();
    }
}
