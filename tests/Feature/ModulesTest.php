<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Company;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Modules;
use App\Support\Navigation;
use App\Support\Permissions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Switching modules on and off.
 *
 * The whole point of the design being one gate check rather than four is that
 * it cannot drift: the navigation, the routes, the quick actions and the
 * components all ask the same question. These tests are mostly about proving
 * that, and about the two ways a module could leak — a nav entry with no
 * ability, and a detail page reached by its direct URL.
 */
class ModulesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Modules::flush();

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
    }

    protected function switchOff(string ...$keys): void
    {
        $this->company->forceFill([
            'modules' => array_fill_keys($keys, false),
        ])->save();

        app(CurrentCompany::class)->set($this->company->fresh());
        Modules::flush();
    }

    // ───────────────────────────────────────────────────────── defaults ──

    public function test_everything_is_on_for_a_new_business(): void
    {
        $enabled = Modules::enabledFor($this->company);

        foreach (array_keys(Modules::catalogue()) as $key) {
            $this->assertContains($key, $enabled, "[{$key}] should be on by default.");
        }
    }

    /**
     * Only departures from the default are stored, so a module added in a
     * later release arrives switched on rather than silently missing for every
     * business whose stored list predates it.
     */
    public function test_a_module_the_business_has_never_heard_of_arrives_switched_on(): void
    {
        $this->switchOff('events');

        $this->assertNotContains('events', Modules::enabledFor($this->company->fresh()));
        $this->assertContains('assets', Modules::enabledFor($this->company->fresh()));
        $this->assertSame(['events' => false], $this->company->fresh()->modules);
    }

    // ─────────────────────────────────────────────────────── the gate ──

    public function test_switching_a_module_off_denies_its_abilities(): void
    {
        $this->assertTrue(Gate::forUser($this->owner)->allows('assets.view'));

        $this->switchOff('assets');

        $this->assertFalse(Gate::forUser($this->owner)->allows('assets.view'));
        $this->assertFalse(Gate::forUser($this->owner)->allows('assets.create'));
        // And nothing else.
        $this->assertTrue(Gate::forUser($this->owner)->allows('sales.view'));
    }

    public function test_a_switched_off_module_answers_403_on_its_route(): void
    {
        $this->actingAs($this->owner)->get(route('assets'))->assertOk();

        $this->switchOff('assets');

        $this->actingAs($this->owner)->get(route('assets'))->assertForbidden();
    }

    /**
     * The failure this is really guarding: a policy check asks `can:view` with
     * a Document, not `can:sales.view`, so mapping abilities alone would leave
     * every detail page reachable by its direct URL.
     */
    public function test_a_switched_off_module_is_not_reachable_through_a_model_policy(): void
    {
        $this->switchOff('sales');

        $this->assertFalse(Gate::forUser($this->owner)->allows('view', new Document));
        $this->assertFalse(Gate::forUser($this->owner)->allows('update', new Document));
    }

    /** The account itself is not a feature of the account. */
    public function test_the_core_abilities_belong_to_no_module_and_cannot_be_switched_off(): void
    {
        foreach (['business.view', 'business.update', 'users.view', 'devices.view', 'settings.view'] as $ability) {
            $this->assertNull(Modules::forAbility($ability), "[{$ability}] must not belong to a module.");
        }

        // Switching everything off leaves the business reachable.
        $this->switchOff(...array_keys(Modules::switchable()));

        $this->actingAs($this->owner)->get(route('settings'))->assertOk();
        $this->actingAs($this->owner)->get(route('dashboard'))->assertOk();
    }

    /** Every catalogued ability is owned by exactly one module, or by none. */
    public function test_no_ability_is_claimed_by_two_modules(): void
    {
        foreach (Permissions::slugs() as $ability) {
            $owners = [];

            foreach (Modules::catalogue() as $key => $module) {
                if (in_array($ability, (array) ($module['abilities'] ?? []), true)) {
                    $owners[] = $key;

                    continue;
                }

                if (in_array($ability, (array) ($module['except'] ?? []), true)) {
                    continue;
                }

                if (in_array(explode('.', $ability, 2)[0], (array) ($module['groups'] ?? []), true)) {
                    $owners[] = $key;
                }
            }

            $this->assertLessThanOrEqual(
                1,
                count($owners),
                "[{$ability}] is claimed by ".implode(' and ', $owners).'.'
            );
        }
    }

    // ─────────────────────────────────────────────────── dependencies ──

    public function test_a_module_whose_requirement_is_off_is_off(): void
    {
        $this->switchOff('hr');

        $enabled = Modules::enabledFor($this->company->fresh());

        $this->assertNotContains('hr', $enabled);
        $this->assertNotContains('payroll', $enabled, 'Payroll reads contracts; without them it cannot work.');
        $this->assertFalse(Gate::forUser($this->owner)->allows('payroll.view'));
    }

    public function test_stock_locations_need_products_and_banking_needs_accounting(): void
    {
        $this->switchOff('products', 'accounting');

        $enabled = Modules::enabledFor($this->company->fresh());

        $this->assertNotContains('stock_locations', $enabled);
        $this->assertNotContains('banking', $enabled);
    }

    public function test_the_dependents_of_a_module_can_be_named_before_it_is_switched_off(): void
    {
        $this->assertSame(['payroll'], Modules::dependents('hr'));
        $this->assertSame(['stock_locations'], Modules::dependents('products'));
        $this->assertSame([], Modules::dependents('payroll'));
    }

    // ─────────────────────────────────────────────────── the navigation ──

    /**
     * Every navigation entry belonging to a module must carry the ability that
     * gates it. One without would stay on screen after the module was switched
     * off and lead to a 403 — which is the exact failure the single gate check
     * exists to prevent.
     */
    public function test_every_module_navigation_entry_is_gated_by_an_ability(): void
    {
        $moduleRoutes = [
            'sales', 'customers', 'products', 'papers', 'forms', 'events',
            'reports', 'accounting', 'payments', 'expenses', 'team', 'payroll',
            'assets', 'banking', 'products.locations', 'partners.clients', 'partners.earnings',
        ];

        foreach (config('opes.navigation') as $entry) {
            if (! in_array($entry['route'], $moduleRoutes, true)) {
                continue;
            }

            $this->assertArrayHasKey('ability', $entry, "Nav entry [{$entry['key']}] has no ability.");
            $this->assertNotNull(
                Modules::forAbility($entry['ability']),
                "Nav entry [{$entry['key']}] is gated by [{$entry['ability']}], which no module owns."
            );
        }
    }

    public function test_the_navigation_and_quick_actions_lose_the_entry_when_a_module_goes(): void
    {
        $this->actingAs($this->owner);

        $keys = fn () => Navigation::items()->pluck('key')->all();
        $actions = fn () => Navigation::quickActions()->pluck('label')->all();

        $this->assertContains('expenses', $keys());
        $this->assertContains('Record Expense', $actions());

        $this->switchOff('expenses');

        $this->assertNotContains('expenses', $keys());
        $this->assertNotContains('Record Expense', $actions(), 'A quick action for a module that is off is a 403 with a nice icon.');
        // Nothing else went with it.
        $this->assertContains('sales', $keys());
    }

    // ──────────────────────────────────────────────────── the switch ──

    public function test_the_settings_screen_switches_a_module_off_and_on_again(): void
    {
        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('toggleModule', 'events');

        $this->assertFalse(Modules::enabled($this->company->fresh(), 'events'));

        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('toggleModule', 'events');

        $this->assertTrue(Modules::enabled($this->company->fresh(), 'events'));
    }

    /** Switching off is not deleting. The data waits. */
    public function test_switching_a_module_off_keeps_its_data(): void
    {
        $employee = Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Yvonne', 'last_name' => 'Ngo Bell',
            'hired_on' => now()->toDateString(), 'status' => 'active',
        ]);

        $this->switchOff('hr');
        $this->actingAs($this->owner)->get(route('team'))->assertForbidden();

        $this->assertDatabaseHas('employees', ['id' => $employee->id]);

        $this->switchOff();
        $this->actingAs($this->owner)->get(route('team'))->assertOk()->assertSee('Yvonne');
    }

    public function test_only_someone_who_can_change_the_business_may_switch_modules(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');

        Livewire::actingAs($cashier)
            ->test(SettingsIndex::class)
            ->call('toggleModule', 'events')
            ->assertForbidden();

        $this->assertTrue(Modules::enabled($this->company->fresh(), 'events'));
    }

    /** A secretariat that switched its own programme off would have signed up for nothing. */
    public function test_the_partner_programme_is_not_switchable(): void
    {
        $this->assertArrayNotHasKey('partners', Modules::switchable());

        Livewire::actingAs($this->owner)
            ->test(SettingsIndex::class)
            ->call('toggleModule', 'partners');

        $this->assertTrue(Modules::enabled($this->company->fresh(), 'partners'));
    }

    public function test_switching_is_per_business(): void
    {
        $this->switchOff('expenses');

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl', 'owner_id' => $otherOwner->id,
            'currency' => 'XAF', 'plan' => 'business', 'account_type' => 'active',
        ]);
        $this->joinCompany($other, $otherOwner, Role::OWNER);

        $this->assertFalse(Modules::enabled($this->company->fresh(), 'expenses'));
        $this->assertTrue(Modules::enabled($other, 'expenses'));
    }
}
