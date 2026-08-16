<?php

namespace Tests\Feature\Fleet;

use App\Livewire\Fleet\Vehicles as VehiclesScreen;
use App\Models\Company;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FuelLog;
use App\Models\Role;
use App\Models\User;
use App\Models\VehicleDetail;
use App\Models\VehicleTrip;
use App\Services\Assets\AssetRegister;
use App\Services\Fleet\FleetAlerts;
use App\Services\Fleet\VehicleUsage;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A vehicle is a fixed asset that happens to be driven.
 *
 * Everything the register already answers — what it cost, where it is, who has
 * it, when it was serviced — is answered for a van by the same tables as for a
 * generator. These tests cover only what a generator never needed: a plate, a
 * milometer, a tank of fuel, and papers that expire.
 */
class FleetTest extends TestCase
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
        app(CurrentCompany::class)->set($this->company);

        ChartOfAccounts::seed($this->company);
    }

    protected function buyVan(array $overrides = []): FixedAsset
    {
        return app(AssetRegister::class)->record(array_merge([
            'name' => 'Toyota Hiace',
            'category' => 'vehicles',
            'acquired_on' => now()->startOfYear()->toDateString(),
            'cost' => 8000000,
            'useful_life_months' => 48,
            'funded_by' => 'bank',
        ], $overrides), $this->owner);
    }

    protected function usage(): VehicleUsage
    {
        return app(VehicleUsage::class);
    }

    // ────────────────────────────────────────────── vehicle details ──

    /**
     * The plate hangs off the asset rather than replacing it. A second vehicle
     * table would mean the van's cost lived in one place and its registration
     * in another, with nothing forcing the two to be the same van.
     */
    public function test_vehicle_details_extend_the_asset_rather_than_replace_it(): void
    {
        $van = $this->buyVan();

        $details = VehicleDetail::create([
            'fixed_asset_id' => $van->id,
            'registration' => 'LT 4821 AB',
            'vin' => 'JTFHX02P900123456',
            'make' => 'Toyota',
            'model' => 'Hiace',
            'year' => 2019,
            'fuel_type' => 'diesel',
            'tank_litres' => 70,
        ]);

        $this->assertSame($van->id, $details->asset->id);
        $this->assertSame('LT 4821 AB', $van->fresh()->vehicle->registration);
        $this->assertSame(8000000.0, (float) $van->fresh()->cost, 'Still one asset, with one cost.');
    }

    /**
     * `registration` is a column, and Eloquent resolves an attribute before a
     * relation of the same name without saying so. The relation on the asset
     * is `vehicle` for exactly the reason `locationRecord` is not `location`.
     */
    public function test_the_vehicle_relation_is_not_shadowed_by_a_column(): void
    {
        $van = $this->buyVan();
        VehicleDetail::create(['fixed_asset_id' => $van->id, 'registration' => 'LT 4821 AB']);

        $this->assertInstanceOf(VehicleDetail::class, $van->fresh()->vehicle);
        $this->assertTrue($van->fresh()->isVehicle());
    }

    // ───────────────────────────────────────────────────────── trips ──

    /** A trip is two milometer readings and who was behind the wheel. */
    public function test_a_trip_records_the_distance_travelled(): void
    {
        $van = $this->buyVan();
        $driver = User::factory()->create();

        $trip = $this->usage()->logTrip($van, [
            'driver_id' => $driver->id,
            'trip_date' => now()->toDateString(),
            'start_odometer' => 41200,
            'end_odometer' => 41560,
            'purpose' => 'Delivery to Bonabéri',
        ], $this->owner->id);

        $this->assertSame(360, $trip->distance());
        $this->assertSame($driver->id, $trip->driver_id);
    }

    /** A milometer does not run backwards, and a trip that says it does is a typo. */
    public function test_a_trip_that_ends_before_it_started_is_refused(): void
    {
        $van = $this->buyVan();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/backwards/');

        $this->usage()->logTrip($van, [
            'trip_date' => now()->toDateString(),
            'start_odometer' => 41560,
            'end_odometer' => 41200,
        ]);
    }

    /**
     * The current reading is worked out from what was actually recorded, not
     * kept in a column of its own. A stored counter drifts the moment somebody
     * corrects a trip, and then no screen agrees about the mileage.
     */
    public function test_the_odometer_is_the_highest_reading_anyone_has_recorded(): void
    {
        $van = $this->buyVan();

        $this->usage()->logTrip($van, [
            'trip_date' => now()->subWeek()->toDateString(),
            'start_odometer' => 41200,
            'end_odometer' => 41560,
        ]);

        $this->usage()->logFuel($van, [
            'filled_on' => now()->toDateString(),
            'litres' => 55,
            'odometer' => 41800,
        ]);

        $this->assertSame(41800, $this->usage()->odometer($van));
    }

    /** Nothing recorded means nothing known — not zero, which would read as a new van. */
    public function test_a_vehicle_with_no_readings_has_no_odometer(): void
    {
        $this->assertNull($this->usage()->odometer($this->buyVan()));
    }

    /**
     * Distance over a period is what a business bills against and budgets on.
     * Date casts store midnight, so a naive range would drop the last day's
     * trips — the one day somebody is most likely to be asking about.
     */
    public function test_distance_over_a_period_includes_the_last_day(): void
    {
        $van = $this->buyVan();

        $this->usage()->logTrip($van, [
            'trip_date' => now()->startOfMonth()->toDateString(),
            'start_odometer' => 41000,
            'end_odometer' => 41100,
        ]);

        $this->usage()->logTrip($van, [
            'trip_date' => now()->endOfMonth()->toDateString(),
            'start_odometer' => 41100,
            'end_odometer' => 41250,
        ]);

        $this->assertSame(250, $this->usage()->distanceTravelled(
            $van,
            now()->startOfMonth(),
            now()->endOfMonth(),
        ));
    }

    // ────────────────────────────────────────────────────────── fuel ──

    /**
     * Fuel keeps no amount of its own. There is already an expense for the
     * fill, and a second copy of the figure would give the month two answers
     * about the same spend — the reason servicing points at an expense too.
     */
    public function test_fuel_reads_its_cost_from_the_expense_that_paid_for_it(): void
    {
        $van = $this->buyVan();

        $bill = Expense::create([
            'company_id' => $this->company->id,
            'reference' => 'EXP-FUEL-1',
            'description' => 'Diesel',
            'category' => 'fuel',
            'issue_date' => now(),
            'amount' => 46750,
            'total' => 46750,
            'status' => 'recorded',
            'recorded_by' => $this->owner->id,
        ]);

        $fill = $this->usage()->logFuel($van, [
            'filled_on' => now()->toDateString(),
            'litres' => 55,
            'odometer' => 41800,
        ], $bill, $this->owner->id);

        $this->assertSame($bill->id, $fill->expense_id);
        $this->assertSame(46750.0, $fill->cost());
        $this->assertFalse(
            Schema::hasColumn('fuel_logs', 'cost'),
            'Fuel holds no amount of its own; the expense is the only copy.'
        );
    }

    /** Another business's bill is not evidence of what this one spent. */
    public function test_fuel_cannot_point_at_another_companys_expense(): void
    {
        $van = $this->buyVan();

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $theirs = Expense::create([
            'company_id' => $other->id,
            'reference' => 'EXP-X',
            'description' => 'Diesel',
            'category' => 'fuel',
            'issue_date' => now(),
            'amount' => 10000,
            'total' => 10000,
            'status' => 'recorded',
            'recorded_by' => $this->owner->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/another company/');

        $this->usage()->logFuel($van, [
            'filled_on' => now()->toDateString(),
            'litres' => 40,
            'odometer' => 41800,
        ], $theirs);
    }

    /**
     * Consumption is measured tank to tank: the distance between the first and
     * last fill, against the fuel put in to cover it. The first fill's litres
     * are excluded because they went into the tank before the first reading —
     * counting them flatters the figure by roughly one tankful.
     */
    public function test_consumption_is_measured_from_one_fill_to_the_next(): void
    {
        $van = $this->buyVan();

        $this->usage()->logFuel($van, ['filled_on' => now()->subMonth()->toDateString(), 'litres' => 60, 'odometer' => 40000]);
        $this->usage()->logFuel($van, ['filled_on' => now()->subWeeks(2)->toDateString(), 'litres' => 50, 'odometer' => 40500]);
        $this->usage()->logFuel($van, ['filled_on' => now()->toDateString(), 'litres' => 50, 'odometer' => 41000]);

        // 1 000 km covered on the 100 litres put in after the first reading.
        $this->assertSame(10.0, $this->usage()->consumptionPer100Km($van));
    }

    /** One fill measures nothing: there is no distance between a reading and itself. */
    public function test_a_single_fill_gives_no_consumption_figure(): void
    {
        $van = $this->buyVan();
        $this->usage()->logFuel($van, ['filled_on' => now()->toDateString(), 'litres' => 60, 'odometer' => 40000]);

        $this->assertNull($this->usage()->consumptionPer100Km($van));
    }

    // ──────────────────────────────────────────────────────── papers ──

    /**
     * The alert exists because insurance lapses quietly. It has to include what
     * has already expired, not only what is about to — the van driving today on
     * cover that ran out last week is the urgent case, not the tidy one.
     */
    public function test_expiring_papers_are_surfaced_including_ones_already_lapsed(): void
    {
        $van = $this->buyVan();
        VehicleDetail::create([
            'fixed_asset_id' => $van->id,
            'registration' => 'LT 4821 AB',
            'insurance_expires_on' => now()->subDays(3),
            'roadworthy_expires_on' => now()->addDays(10),
            'licence_expires_on' => now()->addMonths(6),
        ]);

        $alerts = app(FleetAlerts::class)->expiringPapers(30);

        $this->assertCount(2, $alerts, 'The licence is six months off and is not shouted about.');
        $this->assertSame('Insurance', $alerts->first()['kind'], 'The lapsed one comes first.');
        $this->assertTrue($alerts->first()['lapsed']);
        $this->assertFalse($alerts->last()['lapsed']);
    }

    /**
     * A paper expiring on the very last day of the window is inside it. Date
     * casts are midnight timestamps, so an unbounded comparison drops that day.
     */
    public function test_a_paper_expiring_on_the_last_day_of_the_window_still_counts(): void
    {
        $van = $this->buyVan();
        VehicleDetail::create([
            'fixed_asset_id' => $van->id,
            'insurance_expires_on' => now()->addDays(30),
        ]);

        $this->assertCount(1, app(FleetAlerts::class)->expiringPapers(30));
    }

    // ──────────────────────────────────────────────────────── screen ──

    /** The screen logs a trip through the service, not around it. */
    public function test_the_screen_logs_a_trip(): void
    {
        $van = $this->buyVan();
        VehicleDetail::create(['fixed_asset_id' => $van->id, 'registration' => 'LT 4821 AB']);

        Livewire::actingAs($this->owner)
            ->test(VehiclesScreen::class)
            ->set('tab', 'trips')
            ->set('tripAssetId', $van->id)
            ->set('tripDate', now()->toDateString())
            ->set('tripStart', '41200')
            ->set('tripEnd', '41560')
            ->set('tripPurpose', 'Delivery')
            ->call('logTrip')
            ->assertHasNoErrors();

        $this->assertSame(360, VehicleTrip::first()->distance());
    }

    /** A refusal from the service has to reach the person at the screen. */
    public function test_the_screen_reports_a_refused_trip(): void
    {
        $van = $this->buyVan();

        Livewire::actingAs($this->owner)
            ->test(VehiclesScreen::class)
            ->set('tab', 'trips')
            ->set('tripAssetId', $van->id)
            ->set('tripDate', now()->toDateString())
            ->set('tripStart', '41560')
            ->set('tripEnd', '41200')
            ->call('logTrip')
            ->assertHasErrors('tripEnd');

        $this->assertSame(0, VehicleTrip::count());
    }

    /** The screen records a vehicle's papers against the asset already on the register. */
    public function test_the_screen_saves_vehicle_details(): void
    {
        $van = $this->buyVan();

        Livewire::actingAs($this->owner)
            ->test(VehiclesScreen::class)
            ->call('startVehicle', $van->id)
            ->set('registration', 'LT 4821 AB')
            ->set('make', 'Toyota')
            ->set('fuelType', 'diesel')
            ->set('insuranceExpiresOn', now()->addYear()->toDateString())
            ->call('saveVehicle')
            ->assertHasNoErrors();

        $this->assertSame('LT 4821 AB', $van->fresh()->vehicle->registration);
    }

    /**
     * Driving the van and restating what it cost are different jobs. Logging
     * usage sits with `assets.transfer` — the custodial trust that already
     * covers signing equipment out — not with `assets.update`.
     */
    public function test_logging_usage_needs_the_custodial_permission(): void
    {
        $van = $this->buyVan();
        $clerk = User::factory()->create();
        $this->joinCompany($this->company, $clerk, Role::CASHIER);

        Livewire::actingAs($clerk)
            ->test(VehiclesScreen::class)
            ->set('tripAssetId', $van->id)
            ->set('tripDate', now()->toDateString())
            ->set('tripStart', '1')
            ->set('tripEnd', '2')
            ->call('logTrip')
            ->assertForbidden();
    }

    // ─────────────────────────────────────────────────────── tenancy ──

    /** Fleet records belong to one business, like everything else here. */
    public function test_another_company_cannot_see_this_fleet(): void
    {
        $van = $this->buyVan();
        VehicleDetail::create(['fixed_asset_id' => $van->id, 'registration' => 'LT 4821 AB']);
        $this->usage()->logFuel($van, ['filled_on' => now()->toDateString(), 'litres' => 40, 'odometer' => 100]);

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, VehicleDetail::count());
        $this->assertSame(0, FuelLog::count());
    }
}
