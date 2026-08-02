<?php

namespace Tests\Feature;

use App\Livewire\Assets\Index as AssetsIndex;
use App\Models\Company;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use App\Services\Accounting\Books;
use App\Services\Accounting\Ledger;
use App\Services\Assets\AssetRegister;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The asset register.
 *
 * The thing under test is the distinction between an expense and an asset: a
 * van bought for 8 000 000 must not take 8 000 000 out of one month's profit,
 * and every month it goes on being used must carry a share of it.
 */
class FixedAssetsTest extends TestCase
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

    protected function register(): AssetRegister
    {
        return app(AssetRegister::class);
    }

    protected function buyVan(array $overrides = []): FixedAsset
    {
        return $this->register()->record(array_merge([
            'name' => 'Toyota Hiace',
            'category' => 'vehicles',
            'acquired_on' => now()->startOfYear()->toDateString(),
            'cost' => 8000000,
            'useful_life_months' => 48,
            'funded_by' => 'bank',
        ], $overrides), $this->owner);
    }

    protected function lines($source): array
    {
        $entry = app(Ledger::class)->entryFor($this->company, $source);

        return $entry === null ? [] : $entry->load('lines.account')->lines
            ->mapWithKeys(fn ($l) => [$l->account->number => [round((float) $l->debit, 2), round((float) $l->credit, 2)]])
            ->all();
    }

    // ─────────────────────────────────────────────────── capitalising ──

    /**
     * The whole reason the module exists: buying a van converts money into a
     * different kind of asset. It is not a cost.
     */
    public function test_buying_an_asset_capitalises_it_rather_than_charging_it(): void
    {
        $van = $this->buyVan();

        $lines = $this->lines($van);

        $this->assertSame([8000000.0, 0.0], $lines['245'], 'Matériel de transport.');
        $this->assertSame([0.0, 8000000.0], $lines['521'], 'Straight off the bank.');

        $statement = app(Books::class)->incomeStatement(
            $this->company,
            now()->startOfYear()->toDateString(),
            now()->endOfYear()->toDateString(),
        );

        $this->assertSame(0.0, $statement['total_charges'], 'A purchase is not a cost.');
    }

    public function test_something_bought_on_credit_sits_in_payables(): void
    {
        $van = $this->buyVan(['funded_by' => null]);

        $this->assertSame([0.0, 8000000.0], $this->lines($van)['401']);
    }

    /**
     * An asset carried over from another system is already in somebody's
     * books. Posting it here would capitalise it twice.
     */
    public function test_an_asset_brought_forward_is_not_posted_again(): void
    {
        $van = $this->buyVan(['opening_accumulated' => 3000000, 'post' => false]);

        $this->assertSame([], $this->lines($van));
        $this->assertSame('3000000.00', $van->accumulated_depreciation);
        $this->assertSame(5000000.0, $van->bookValue());
    }

    // ────────────────────────────────────────────────── depreciation ──

    /** 8 000 000 over 48 months is 166 667 a month, and it posts. */
    public function test_a_month_of_depreciation_charges_the_books(): void
    {
        $van = $this->buyVan();

        $entry = $this->register()->depreciate($van, now()->startOfYear()->toDateString(), $this->owner);

        $this->assertSame('166666.67', $entry->amount);

        $lines = $this->lines($entry);

        $this->assertSame([166666.67, 0.0], $lines['681'], 'Dotation aux amortissements.');
        $this->assertSame([0.0, 166666.67], $lines['284'], 'Accumulated depreciation on the matériel.');
    }

    /** Running the same month twice must not depreciate the van twice. */
    public function test_depreciating_a_month_is_idempotent(): void
    {
        $van = $this->buyVan();
        $period = now()->startOfYear()->toDateString();

        $this->register()->depreciate($van, $period, $this->owner);
        $this->register()->depreciate($van->refresh(), $period, $this->owner);

        $this->assertSame(1, DepreciationEntry::query()->count());
        $this->assertSame('166666.67', $van->refresh()->accumulated_depreciation);
    }

    /**
     * The last charge takes whatever rounding left behind, so nothing is
     * stranded on the balance sheet forever.
     */
    public function test_an_asset_depreciates_to_exactly_its_residual_value(): void
    {
        $laptop = $this->register()->record([
            'name' => 'Laptop', 'category' => 'computers',
            'acquired_on' => now()->startOfYear()->toDateString(),
            'cost' => 100000, 'useful_life_months' => 3, 'funded_by' => 'cash',
        ], $this->owner);

        for ($i = 0; $i < 3; $i++) {
            $this->register()->depreciate(
                $laptop->refresh(),
                now()->startOfYear()->addMonths($i)->toDateString(),
                $this->owner
            );
        }

        $laptop->refresh();

        $this->assertSame(0.0, $laptop->bookValue());
        $this->assertSame(100000.0, round((float) DepreciationEntry::query()->sum('amount'), 2));
        $this->assertTrue($laptop->isFullyDepreciated());
    }

    public function test_a_residual_value_is_the_floor(): void
    {
        $machine = $this->register()->record([
            'name' => 'Groupe électrogène', 'category' => 'equipment',
            'acquired_on' => now()->startOfYear()->toDateString(),
            'cost' => 1000000, 'residual_value' => 200000,
            'useful_life_months' => 4, 'funded_by' => 'cash',
        ], $this->owner);

        for ($i = 0; $i < 6; $i++) {
            $this->register()->depreciate(
                $machine->refresh(),
                now()->startOfYear()->addMonths($i)->toDateString(),
                $this->owner
            );
        }

        $this->assertSame(200000.0, $machine->refresh()->bookValue(), 'It never falls below what it will be worth.');
    }

    /** Land does not wear out. */
    public function test_land_is_not_depreciated(): void
    {
        $plot = $this->register()->record([
            'name' => 'Terrain Bonabéri', 'category' => 'land',
            'acquired_on' => now()->startOfYear()->toDateString(),
            'cost' => 15000000, 'funded_by' => 'bank',
        ], $this->owner);

        $this->assertFalse($plot->isDepreciable());
        $this->assertNull($plot->depreciation_account_id);
        $this->assertNull($this->register()->depreciate($plot, now()->toDateString(), $this->owner));
        $this->assertSame(15000000.0, $plot->refresh()->bookValue());
    }

    public function test_an_asset_not_yet_in_use_is_not_charged(): void
    {
        $van = $this->buyVan([
            'acquired_on' => now()->addMonths(2)->toDateString(),
            'in_service_on' => now()->addMonths(2)->toDateString(),
        ]);

        $this->assertNull($this->register()->depreciate($van, now()->toDateString(), $this->owner));
    }

    public function test_running_the_month_charges_every_active_asset(): void
    {
        $this->buyVan();
        $this->register()->record([
            'name' => 'Comptoir', 'category' => 'furniture',
            'acquired_on' => now()->startOfYear()->toDateString(),
            'cost' => 600000, 'useful_life_months' => 120, 'funded_by' => 'cash',
        ], $this->owner);

        $result = $this->register()->depreciateAll(now()->startOfYear()->toDateString(), $this->owner);

        $this->assertSame(2, $result['charged']);
        $this->assertSame(171666.67, $result['total']);

        // And a second run charges nothing at all.
        $this->assertSame(0, $this->register()->depreciateAll(now()->startOfYear()->toDateString(), $this->owner)['charged']);
    }

    public function test_the_charge_reaches_the_income_statement(): void
    {
        $van = $this->buyVan();
        $this->register()->depreciate($van, now()->startOfYear()->toDateString(), $this->owner);

        $statement = app(Books::class)->incomeStatement(
            $this->company,
            now()->startOfYear()->toDateString(),
            now()->endOfYear()->toDateString(),
        );

        $this->assertSame(166666.67, $statement['total_charges']);
        $this->assertTrue($statement['charges']->contains(fn ($r) => $r['account']->number === '681'));
    }

    // ───────────────────────────────────────────────────── disposals ──

    /**
     * Sold for more than it was worth. Nobody is asked for the gain: it is the
     * difference between 812 and 822, and asking would invite a figure that
     * disagrees with the arithmetic.
     */
    public function test_selling_an_asset_leaves_the_gain_to_fall_out_of_the_entry(): void
    {
        $van = $this->buyVan();

        // Write off half of it first.
        for ($i = 0; $i < 24; $i++) {
            $this->register()->depreciate($van->refresh(), now()->startOfYear()->addMonths($i)->toDateString(), $this->owner);
        }

        $van->refresh();
        $bookValue = $van->bookValue();
        // Twenty-four charges of 166 666.67 do not come to exactly 4 000 000,
        // so the entry is checked against what was actually written off rather
        // than against arithmetic done by hand — the point is that the ledger
        // agrees with the register, not that the register is a round number.
        $accumulated = round((float) $van->accumulated_depreciation, 2);

        $this->register()->dispose($van, [
            'proceeds' => 5000000,
            'received_by' => 'bank',
            'disposed_on' => now()->toDateString(),
        ], $this->owner);

        $entry = JournalEntry::query()
            ->where('narration', 'like', 'Cession%')
            ->with('lines.account')
            ->firstOrFail();

        $lines = $entry->lines->mapWithKeys(fn ($l) => [$l->account->number => [(float) $l->debit, (float) $l->credit]])->all();

        $this->assertSame([0.0, 8000000.0], $lines['245'], 'The cost leaves the balance sheet.');
        $this->assertSame([$accumulated, 0.0], $lines['284'], 'And so does its accumulated depreciation.');
        $this->assertSame([$bookValue, 0.0], $lines['812'], 'What was left of its value becomes a cost.');
        $this->assertSame([0.0, 5000000.0], $lines['822'], 'The money received becomes income.');
        $this->assertSame([5000000.0, 0.0], $lines['521']);

        // Sold for 5 000 000 against whatever book value was left: the gain is
        // the difference, and nobody was asked for it.
        $statement = app(Books::class)->incomeStatement(
            $this->company,
            now()->startOfYear()->toDateString(),
            now()->endOfYear()->toDateString(),
        );

        $this->assertSame(
            round(5000000 - $bookValue, 2),
            round($statement['total_hao_produits'] - $statement['total_hao_charges'], 2)
        );
    }

    public function test_scrapping_an_asset_is_a_disposal_for_nothing(): void
    {
        $van = $this->buyVan();

        $this->register()->dispose($van, ['proceeds' => 0], $this->owner);

        $this->assertSame('written_off', $van->refresh()->status);
        $this->assertTrue($van->isDisposed());
    }

    public function test_an_asset_cannot_be_disposed_of_twice(): void
    {
        $van = $this->buyVan();
        $this->register()->dispose($van, ['proceeds' => 100000], $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been disposed');

        $this->register()->dispose($van->refresh(), ['proceeds' => 100000], $this->owner);
    }

    public function test_a_disposed_asset_stops_depreciating(): void
    {
        $van = $this->buyVan();
        $this->register()->dispose($van, ['proceeds' => 0], $this->owner);

        $this->assertNull($this->register()->depreciate($van->refresh(), now()->toDateString(), $this->owner));
    }

    // ──────────────────────────────────────────── the books still balance ──

    /**
     * A gain on disposal sits in class 8, which is neither actif nor passif —
     * so it only meets the balance sheet through the result. If this fails,
     * the two sides have come apart.
     */
    public function test_the_balance_sheet_still_balances_after_a_disposal(): void
    {
        $van = $this->buyVan();

        for ($i = 0; $i < 12; $i++) {
            $this->register()->depreciate($van->refresh(), now()->startOfYear()->addMonths($i)->toDateString(), $this->owner);
        }

        $this->register()->dispose($van->refresh(), ['proceeds' => 7000000, 'received_by' => 'bank'], $this->owner);

        $sheet = app(Books::class)->balanceSheet(
            $this->company,
            now()->startOfYear()->toDateString(),
            now()->endOfYear()->toDateString(),
        );

        $this->assertTrue($sheet['balanced'], 'Actif '.$sheet['total_actif'].' vs passif '.$sheet['total_passif']);
    }

    // ────────────────────────────────────────────────────────── screen ──

    public function test_the_screen_records_an_asset_and_charges_a_month(): void
    {
        Livewire::actingAs($this->owner)
            ->test(AssetsIndex::class)
            ->call('startAdding')
            ->set('name', 'Groupe électrogène')
            ->set('category', 'equipment')
            ->set('acquiredOn', now()->startOfMonth()->toDateString())
            ->set('cost', '2400000')
            ->set('usefulLifeMonths', '60')
            ->call('save')
            ->assertHasNoErrors()
            ->call('runDepreciation')
            ->assertOk();

        $this->assertSame(1, FixedAsset::query()->count());
        $this->assertSame('40000.00', DepreciationEntry::query()->firstOrFail()->amount);
    }

    public function test_a_residual_higher_than_the_cost_is_refused(): void
    {
        Livewire::actingAs($this->owner)
            ->test(AssetsIndex::class)
            ->call('startAdding')
            ->set('name', 'Machine')
            ->set('cost', '100000')
            ->set('residualValue', '150000')
            ->call('save')
            ->assertHasErrors('residualValue');
    }

    public function test_a_cashier_cannot_see_the_register(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');

        $this->actingAs($cashier)->get(route('assets'))->assertForbidden();
    }

    public function test_the_register_belongs_to_its_company_alone(): void
    {
        $this->buyVan();

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl', 'owner_id' => $otherOwner->id,
            'currency' => 'XAF', 'plan' => 'business', 'account_type' => 'active',
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);
        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, FixedAsset::query()->count());
    }
}
