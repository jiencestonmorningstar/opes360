<?php

namespace Tests\Feature;

use App\Livewire\Assets\Movements as MovementsScreen;
use App\Models\AssetLocation;
use App\Models\AssetMaintenance;
use App\Models\Company;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\Role;
use App\Models\User;
use App\Services\Assets\AssetMovements;
use App\Services\Assets\AssetRegister;
use App\Services\Assets\AssetServicing;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * Where the business's things are, who has them, and when they were serviced.
 *
 * The register already answers what is owned and what it is worth. These tests
 * cover the questions asked when something is needed rather than counted: where
 * is the generator, who signed for the laptop, when was the van last serviced.
 */
class AssetMovementsTest extends TestCase
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

    protected function site(string $name): AssetLocation
    {
        return AssetLocation::create(['name' => $name]);
    }

    protected function movements(): AssetMovements
    {
        return app(AssetMovements::class);
    }

    // ───────────────────────────────────────────────────── transfers ──

    /**
     * The point of the feature: an asset's position and the record of how it
     * got there are written together. One without the other is an asset that
     * is somewhere with no explanation, which is the state this replaces.
     */
    public function test_transferring_moves_the_asset_and_records_the_move(): void
    {
        $van = $this->buyVan();
        $depot = $this->site('Bonabéri Depot');

        $transfer = $this->movements()->transfer($van, ['to_location_id' => $depot->id], userId: $this->owner->id);

        $this->assertSame($depot->id, $van->fresh()->asset_location_id, 'The van is at the depot.');
        $this->assertSame($depot->id, $transfer->to_location_id);
        $this->assertNull($transfer->from_location_id, 'It had been nowhere in particular.');
        $this->assertTrue($transfer->movedSite());
    }

    /**
     * Chasing a missing asset means asking where it has been, not only where
     * it is. Each move is kept, and the newest is the one shown first.
     */
    public function test_every_move_is_kept_in_order(): void
    {
        $van = $this->buyVan();
        $depot = $this->site('Bonabéri Depot');
        $yard = $this->site('Douala Yard');

        $this->movements()->transfer($van, ['to_location_id' => $depot->id], now()->subMonth());
        $this->movements()->transfer($van, ['to_location_id' => $yard->id], now());

        $history = $van->fresh()->transfers;

        $this->assertCount(2, $history);
        $this->assertSame($yard->id, $history->first()->to_location_id, 'Newest first.');
        $this->assertSame($depot->id, $history->first()->from_location_id, 'It came from the depot.');
    }

    /**
     * An asset can change hands without moving an inch. A laptop handed from
     * one person to another at the same desk is still a transfer, and it is
     * the one most worth recording.
     */
    public function test_an_asset_can_change_hands_without_moving(): void
    {
        $van = $this->buyVan();
        $depot = $this->site('Bonabéri Depot');
        $this->movements()->transfer($van, ['to_location_id' => $depot->id]);

        $driver = User::factory()->create();
        $transfer = $this->movements()->transfer($van->fresh(), ['to_custodian_id' => $driver->id]);

        $this->assertSame($driver->id, $van->fresh()->custodian_id);
        $this->assertSame($depot->id, $transfer->to_location_id, 'Still at the depot.');
        $this->assertFalse($transfer->movedSite());
        $this->assertTrue($transfer->changedHands());
    }

    /**
     * Handing something back to the store is a real answer, not a missing one.
     * A present null clears the custodian; an absent key leaves it alone.
     */
    public function test_clearing_a_custodian_differs_from_not_mentioning_one(): void
    {
        $van = $this->buyVan();
        $driver = User::factory()->create();
        $this->movements()->transfer($van, ['to_custodian_id' => $driver->id]);

        $returned = $this->movements()->transfer($van->fresh(), ['to_custodian_id' => null]);

        $this->assertNull($van->fresh()->custodian_id, 'Nobody holds it now.');
        $this->assertSame($driver->id, $returned->from_custodian_id, 'It came back from the driver.');
    }

    /** A transfer that changes nothing is a mistake, and saying so beats a silent no-op. */
    public function test_a_transfer_that_changes_nothing_is_refused(): void
    {
        $van = $this->buyVan();
        $depot = $this->site('Bonabéri Depot');
        $this->movements()->transfer($van, ['to_location_id' => $depot->id]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already there/');

        $this->movements()->transfer($van->fresh(), ['to_location_id' => $depot->id]);
    }

    /** Something sold or scrapped is not somewhere. Moving it means the register is wrong. */
    public function test_a_disposed_asset_cannot_be_transferred(): void
    {
        $van = $this->buyVan();
        $van->update(['status' => 'disposed']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/disposed/');

        $this->movements()->transfer($van, ['to_location_id' => $this->site('Yard')->id]);
    }

    /**
     * The screens written before locations existed read the free-text column.
     * Leaving it showing the old site would make two parts of the product
     * disagree about where the same van is.
     */
    public function test_the_original_free_text_location_is_kept_in_step(): void
    {
        $van = $this->buyVan(['location' => 'Head Office']);
        $depot = $this->site('Bonabéri Depot');

        $this->movements()->transfer($van, ['to_location_id' => $depot->id]);

        $this->assertSame('Bonabéri Depot', $van->fresh()->location);
    }

    /**
     * `location` is a string column on the model, so a relation of that name
     * would be shadowed by it and silently never load. This pins the reason
     * the relation is called `locationRecord`.
     */
    public function test_the_location_relation_is_not_shadowed_by_the_text_column(): void
    {
        $van = $this->buyVan(['location' => 'Head Office']);
        $depot = $this->site('Bonabéri Depot');
        $this->movements()->transfer($van, ['to_location_id' => $depot->id]);

        $fresh = $van->fresh();

        $this->assertIsString($fresh->location, 'The attribute still wins, as Eloquent insists.');
        $this->assertInstanceOf(AssetLocation::class, $fresh->locationRecord);
        $this->assertSame('Bonabéri Depot', $fresh->locationName());
    }

    // ─────────────────────────────────────────────────── maintenance ──

    /** Overdue servicing is what the feature exists to surface. */
    public function test_outstanding_servicing_shows_up_as_overdue(): void
    {
        $van = $this->buyVan();

        $due = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => '10 000 km service',
            'due_on' => now()->subWeek(),
        ]);

        $this->assertTrue($due->isOverdue());
        $this->assertFalse($due->isDone());
        $this->assertCount(1, AssetMaintenance::dueBy(now())->get());
    }

    /**
     * The cost is read off the expense that paid for it. Typing the amount
     * here as well would give the month two answers about the same spend.
     */
    public function test_completing_a_service_reads_its_cost_from_the_expense(): void
    {
        $van = $this->buyVan();
        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => 'Oil change',
            'due_on' => now(),
        ]);

        $bill = Expense::create([
            'company_id' => $this->company->id,
            'reference' => 'EXP-1',
            'description' => 'Oil change',
            'category' => 'maintenance',
            'issue_date' => now(),
            'amount' => 45000,
            'total' => 45000,
            'status' => 'recorded',
            'recorded_by' => $this->owner->id,
        ]);

        $done = app(AssetServicing::class)->complete($job, now(), $bill);

        $this->assertTrue($done->isDone());
        $this->assertSame($bill->id, $done->expense_id);
        $this->assertSame('45000.00', (string) $done->cost, 'Read off the bill, not typed twice.');
    }

    /**
     * Servicing that repeats raises the next one from the day the work was
     * actually done. An oil change six weeks late resets the clock — booking
     * the next from the original due date brings it forward for no reason.
     */
    public function test_repeating_servicing_is_rescheduled_from_when_it_was_done(): void
    {
        $van = $this->buyVan();
        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => 'Quarterly service',
            'due_on' => now()->subWeeks(6),
            'interval_months' => 3,
        ]);

        app(AssetServicing::class)->complete($job, now());

        $next = AssetMaintenance::outstanding()->where('fixed_asset_id', $van->id)->first();

        $this->assertNotNull($next, 'The next service was raised.');
        $this->assertSame(
            now()->addMonths(3)->toDateString(),
            $next->due_on->toDateString(),
            'Three months from the work, not from the missed date.'
        );
        $this->assertSame(3, (int) $next->interval_months, 'And it goes on repeating.');
    }

    /** One-off work does not quietly become a standing commitment. */
    public function test_one_off_servicing_does_not_repeat(): void
    {
        $van = $this->buyVan();
        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'repair',
            'title' => 'Replace windscreen',
            'due_on' => now(),
        ]);

        app(AssetServicing::class)->complete($job, now());

        $this->assertSame(0, AssetMaintenance::outstanding()->count());
    }

    /** Completing the same visit twice would double-count its cost. */
    public function test_a_completed_service_cannot_be_completed_again(): void
    {
        $van = $this->buyVan();
        $job = AssetMaintenance::create([
            'fixed_asset_id' => $van->id,
            'kind' => 'service',
            'title' => 'Oil change',
            'due_on' => now(),
        ]);

        app(AssetServicing::class)->complete($job, now());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already completed/');

        app(AssetServicing::class)->complete($job->fresh(), now());
    }

    // ─────────────────────────────────────────────────────── screen ──

    /** The screen records a transfer through the service, not around it. */
    public function test_the_screen_transfers_an_asset(): void
    {
        $van = $this->buyVan();
        $depot = $this->site('Bonabéri Depot');

        Livewire::actingAs($this->owner)
            ->test(MovementsScreen::class)
            ->call('startTransfer', $van->id)
            ->set('toLocationId', $depot->id)
            ->set('transferredOn', now()->toDateString())
            ->set('reason', 'Moved to the depot for the rains')
            ->call('transfer')
            ->assertHasNoErrors();

        $this->assertSame($depot->id, $van->fresh()->asset_location_id);
        $this->assertSame(1, $van->transfers()->count(), 'And the move was recorded.');
    }

    /**
     * A refusal from the service has to reach the person at the screen. A
     * transfer that silently does nothing is worse than one that fails.
     */
    public function test_the_screen_reports_a_refused_transfer(): void
    {
        $van = $this->buyVan();
        $depot = $this->site('Bonabéri Depot');
        $this->movements()->transfer($van, ['to_location_id' => $depot->id]);

        Livewire::actingAs($this->owner)
            ->test(MovementsScreen::class)
            ->call('startTransfer', $van->id)
            ->set('transferredOn', now()->toDateString())
            ->call('transfer')
            ->assertHasErrors('transferring');

        $this->assertSame(1, $van->transfers()->count(), 'No second move was written.');
    }

    /** Moving equipment is a storeman's job; restating its cost is not. */
    public function test_transferring_needs_its_own_permission(): void
    {
        $van = $this->buyVan();
        $clerk = User::factory()->create();
        $this->joinCompany($this->company, $clerk, Role::CASHIER);

        Livewire::actingAs($clerk)
            ->test(MovementsScreen::class)
            ->call('startTransfer', $van->id)
            ->assertForbidden();
    }

    // ────────────────────────────────────────────────────── tenancy ──

    /** Sites belong to one business, like everything else here. */
    public function test_another_company_cannot_see_these_sites(): void
    {
        $this->site('Bonabéri Depot');

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, AssetLocation::count());
    }
}
