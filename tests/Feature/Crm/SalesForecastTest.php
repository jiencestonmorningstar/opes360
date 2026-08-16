<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Role;
use App\Models\User;
use App\Services\DealPipeline;
use App\Support\CurrentCompany;
use App\Support\SalesForecast;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The weighted pipeline.
 *
 * A pure read over the deals that already exist — the forecast maintains no
 * numbers of its own, so it cannot disagree with the board. Weight is amount ×
 * stage probability, bucketed by expected close month.
 */
class SalesForecastTest extends TestCase
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
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
    }

    protected function makeDeal(array $attributes = []): Deal
    {
        return app(DealPipeline::class)->create(array_merge([
            'title' => 'Deal '.Str::random(4),
            'lead_name' => 'Jane Mbeki',
            'value' => 1000000,
        ], $attributes), $this->owner);
    }

    public function test_weight_is_amount_times_stage_probability(): void
    {
        $this->makeDeal(['stage' => 'proposal', 'expected_close_on' => now()->addDays(3)->toDateString()]);

        $forecast = (new SalesForecast)->build();

        $expected = 1000000 * Deal::STAGE_PROBABILITIES['proposal'] / 100;

        $this->assertEquals($expected, $forecast['total_weighted']);
        $this->assertEquals(1000000, $forecast['total_gross']);
    }

    public function test_a_deal_level_probability_overrides_the_stage_default(): void
    {
        $deal = $this->makeDeal(['stage' => 'proposal', 'expected_close_on' => now()->addDays(3)->toDateString()]);
        $deal->forceFill(['probability' => 90])->save();

        $forecast = (new SalesForecast)->build();

        $this->assertEquals(900000, $forecast['total_weighted']);
    }

    public function test_closed_deals_are_not_forecast(): void
    {
        $won = $this->makeDeal(['expected_close_on' => now()->addDays(3)->toDateString()]);
        app(DealPipeline::class)->moveTo($won, 'won');

        $lost = $this->makeDeal(['expected_close_on' => now()->addDays(3)->toDateString()]);
        app(DealPipeline::class)->moveTo($lost, 'lost');

        $forecast = (new SalesForecast)->build();

        // Won money is revenue, lost money is gone — neither is a forecast.
        $this->assertEquals(0, $forecast['total_weighted']);
    }

    public function test_the_last_day_of_a_month_lands_in_that_month(): void
    {
        // The date-cast trap: expected_close_on is stored at midnight, and a
        // naive whereBetween on month bounds drops the last day. This pins the
        // bucketing to the calendar month regardless.
        $from = CarbonImmutable::now()->startOfMonth();
        $lastDay = $from->endOfMonth()->toDateString();

        $this->makeDeal(['stage' => 'qualified', 'expected_close_on' => $lastDay]);

        $forecast = (new SalesForecast(from: $from))->build();
        $firstMonth = $forecast['months']->first();

        $this->assertSame($from->format('Y-m'), $firstMonth['month']);
        $this->assertEquals(1000000 * Deal::STAGE_PROBABILITIES['qualified'] / 100, $firstMonth['weighted']);
    }

    public function test_an_overdue_open_deal_is_forecast_in_the_current_month(): void
    {
        // "Expected to close last month and still open" does not mean the money
        // vanished — the soonest honest answer for it is now.
        $this->makeDeal(['stage' => 'proposal', 'expected_close_on' => now()->subMonths(2)->toDateString()]);

        $forecast = (new SalesForecast)->build();

        $this->assertEquals(600000, $forecast['months']->first()['weighted']);
    }

    public function test_a_deal_with_no_close_date_is_unscheduled_not_dropped(): void
    {
        $this->makeDeal(['stage' => 'qualified', 'expected_close_on' => null]);

        $forecast = (new SalesForecast)->build();

        $this->assertEquals(300000, $forecast['unscheduled']['weighted']);
        // Still counted in the grand total: hiding it would understate the pipeline.
        $this->assertEquals(300000, $forecast['total_weighted']);
    }

    public function test_the_forecast_splits_by_owner(): void
    {
        $second = User::factory()->create();
        $this->joinCompany($this->company, $second, 'sales-officer');

        $this->makeDeal(['stage' => 'qualified', 'owner_id' => $this->owner->id, 'expected_close_on' => now()->addDay()->toDateString()]);
        $this->makeDeal(['stage' => 'qualified', 'owner_id' => $second->id, 'value' => 2000000, 'expected_close_on' => now()->addDay()->toDateString()]);

        $forecast = (new SalesForecast)->build();
        $byOwner = $forecast['owners']->keyBy('owner_id');

        $this->assertEquals(300000, $byOwner[$this->owner->id]['weighted']);
        $this->assertEquals(600000, $byOwner[$second->id]['weighted']);
    }
}
