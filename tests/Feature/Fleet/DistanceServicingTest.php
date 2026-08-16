<?php

namespace Tests\Feature\Fleet;

use App\Models\AssetMaintenance;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\Role;
use App\Models\User;
use App\Models\VehicleDetail;
use App\Services\Assets\AssetRegister;
use App\Services\Assets\AssetServicing;
use App\Services\Fleet\FleetAlerts;
use App\Services\Fleet\VehicleUsage;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Servicing a van by how far it has gone, not by what month it is.
 *
 * A generator is serviced every six months whatever it did. A van is serviced
 * every 10 000 km, and a van that sat in the yard all quarter does not need
 * one. This is the same maintenance record with a second kind of due marker,
 * not a second scheduler — a parallel one would mean two lists of what is
 * outstanding and no single answer to "what is overdue".
 */
class DistanceServicingTest extends TestCase
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

    protected function van(): FixedAsset
    {
        $van = app(AssetRegister::class)->record([
            'name' => 'Toyota Hiace',
            'category' => 'vehicles',
            'acquired_on' => now()->startOfYear()->toDateString(),
            'cost' => 8000000,
            'useful_life_months' => 48,
            'funded_by' => 'bank',
        ], $this->owner);

        VehicleDetail::create(['fixed_asset_id' => $van->id, 'registration' => 'LT 4821 AB']);

        return $van;
    }

    protected function drive(FixedAsset $van, int $from, int $to): void
    {
        app(VehicleUsage::class)->logTrip($van, [
            'trip_date' => now()->toDateString(),
            'start_odometer' => $from,
            'end_odometer' => $to,
        ]);
    }

    /** A distance-due job is not overdue on the calendar; it is overdue on the clock face. */
    public function test_servicing_falls_due_when_the_van_reaches_the_reading(): void
    {
        $van = $this->van();

        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => '10 000 km service',
            'due_at_odometer' => 50000,
            'interval_km' => 10000,
        ]);

        $this->drive($van, 40000, 49900);
        $this->assertFalse($job->isDueAtDistance(app(VehicleUsage::class)->odometer($van)));

        $this->drive($van, 49900, 50100);
        $this->assertTrue($job->isDueAtDistance(app(VehicleUsage::class)->odometer($van)));
    }

    /** With nothing recorded there is no reading, and no reading is not "not yet due". */
    public function test_an_unknown_odometer_does_not_make_a_job_due(): void
    {
        $van = $this->van();

        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => '10 000 km service',
            'due_at_odometer' => 50000,
        ]);

        $this->assertFalse($job->isDueAtDistance(null));
    }

    /**
     * The next one is counted from the reading the work was actually done at,
     * exactly as a repeating date service counts from the day it was done. An
     * oil change carried out 800 km late moves the whole schedule on by 800 km;
     * counting from the planned reading would book the next one early forever.
     */
    public function test_the_next_service_is_counted_from_the_reading_it_was_done_at(): void
    {
        $van = $this->van();

        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => '10 000 km service',
            'due_at_odometer' => 50000,
            'interval_km' => 10000,
        ]);

        $this->drive($van, 40000, 50800);

        app(AssetServicing::class)->complete($job, now());

        $next = AssetMaintenance::outstanding()->where('fixed_asset_id', $van->id)->first();

        $this->assertNotNull($next, 'The next service was raised.');
        $this->assertSame(60800, (int) $next->due_at_odometer, 'Ten thousand on from the work, not from the plan.');
        $this->assertSame(10000, (int) $next->interval_km, 'And it goes on repeating.');
        $this->assertSame(50800, (int) $job->fresh()->completed_at_odometer);
    }

    /** A reading typed at the counter beats one inferred from the logs. */
    public function test_the_reading_can_be_given_at_completion(): void
    {
        $van = $this->van();

        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => 'Oil change',
            'interval_km' => 5000,
        ]);

        app(AssetServicing::class)->complete($job, now(), null, 33000);

        $this->assertSame(38000, (int) AssetMaintenance::outstanding()->first()->due_at_odometer);
    }

    /**
     * "Every 10 000 km" from an unknown starting point is not a schedule, it is
     * a guess. Refusing is better than raising a job due at a reading nobody
     * can defend, which would then sit overdue or invisible for ever.
     */
    public function test_repeating_by_distance_needs_a_reading_to_count_from(): void
    {
        $van = $this->van();

        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => 'Oil change',
            'interval_km' => 5000,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/odometer/');

        app(AssetServicing::class)->complete($job, now());
    }

    /** A van serviced on both counts gets whichever comes first, from one row. */
    public function test_a_job_can_repeat_on_time_and_distance_at_once(): void
    {
        $van = $this->van();

        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => 'Full service',
            'due_on' => now()->subWeeks(2),
            'interval_months' => 6,
            'interval_km' => 10000,
        ]);

        $this->drive($van, 40000, 52000);

        app(AssetServicing::class)->complete($job, now());

        $next = AssetMaintenance::outstanding()->first();

        $this->assertSame(now()->addMonths(6)->toDateString(), $next->due_on->toDateString());
        $this->assertSame(62000, (int) $next->due_at_odometer);
    }

    /**
     * The whole point is one list. Servicing due because the van has driven far
     * enough has to appear beside servicing due because the date came round.
     */
    public function test_distance_due_servicing_shows_up_alongside_date_due_servicing(): void
    {
        $van = $this->van();
        $this->drive($van, 40000, 51000);

        AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => '10 000 km service',
            'due_at_odometer' => 50000,
        ]);

        AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'title' => 'Annual inspection',
            'kind' => 'inspection',
            'due_on' => now()->subWeek(),
        ]);

        $due = app(FleetAlerts::class)->servicingDue();

        $this->assertCount(2, $due, 'Both reasons for being overdue reach the same list.');
    }

    /** Existing date-only servicing is untouched by any of this. */
    public function test_a_job_with_no_distance_terms_behaves_exactly_as_before(): void
    {
        $van = $this->van();

        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => 'Quarterly service',
            'due_on' => now()->subWeeks(6),
            'interval_months' => 3,
        ]);

        app(AssetServicing::class)->complete($job, now());

        $next = AssetMaintenance::outstanding()->first();

        $this->assertSame(now()->addMonths(3)->toDateString(), $next->due_on->toDateString());
        $this->assertNull($next->due_at_odometer);
        $this->assertNull($next->interval_km);
    }
}
