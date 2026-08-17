<?php

namespace Tests\Feature;

use App\Livewire\Business\Companies;
use App\Livewire\Dashboard;
use App\Livewire\Onboarding\Register;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\DemoAccountProvisioner;
use App\Support\CurrentCompany;
use App\Support\Modules;
use App\Support\Sectors;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The sector question at signup.
 *
 * The promises under test: every sector yields a dependency-closed module
 * set; skipping yields today's defaults untouched; the first-run card shows
 * once and dismisses; Settings stays the source of truth afterwards — the
 * sector is never re-applied except by the explicit reset action.
 */
class SectorOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Modules::flush();
    }

    protected function makeCompany(?User $owner = null): Company
    {
        $owner ??= User::factory()->create();

        $company = Company::create([
            'slug' => 'biz-'.Str::lower(Str::random(6)),
            'name' => 'Biz Sarl',
            'owner_id' => $owner->id,
            'currency' => 'XAF',
        ]);

        $this->joinCompany($company, $owner, Role::OWNER);

        return $company;
    }

    protected function register(string $sector = ''): Company
    {
        Livewire::test(Register::class)
            ->set('name', 'Ada Obi')
            ->set('email', 'ada-'.Str::lower(Str::random(5)).'@example.com')
            ->set('password', 'secret-password')
            ->set('passwordConfirmation', 'secret-password')
            ->call('continueToBusiness')
            ->set('businessName', 'Biz '.Str::random(5))
            ->set('sector', $sector)
            ->call('finish')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        return Company::latest('id')->firstOrFail();
    }

    // ─────────────────────────────────────────────────── the catalogue ──

    /**
     * The load-bearing invariant: no sector may yield a module missing its
     * requirements. Everything a sector says is on must actually come on —
     * if a `requires` chain silently switched it back off, the signup would
     * promise a vertical the navigation never shows.
     */
    public function test_every_sector_yields_a_dependency_closed_module_set(): void
    {
        foreach (array_keys(Sectors::catalogue()) as $slug) {
            $company = $this->makeCompany();
            Sectors::apply($company, $slug);

            $enabled = Modules::enabledFor($company->fresh());

            foreach (Sectors::modulesFor($slug) as $key => $wanted) {
                $this->assertTrue(Modules::exists($key), "[{$slug}] names unknown module [{$key}].");

                $wanted
                    ? $this->assertContains($key, $enabled, "[{$slug}] promised [{$key}] on, but a missing requirement switched it off.")
                    : $this->assertNotContains($key, $enabled, "[{$slug}] promised [{$key}] off.");
            }

            // And the closure holds across the whole enabled set.
            foreach ($enabled as $key) {
                foreach ((array) (Modules::catalogue()[$key]['requires'] ?? []) as $needed) {
                    $this->assertContains($needed, $enabled, "[{$slug}] left [{$key}] on without [{$needed}].");
                }
            }
        }
    }

    public function test_a_sector_writes_only_explicit_departures(): void
    {
        $company = $this->makeCompany();
        Sectors::apply($company, 'insurance_broker');

        $stored = $company->fresh()->modules;

        // Every stored key is a deliberate true/false; nothing default-on and
        // unmentioned is written, so future catalogue additions arrive on.
        $this->assertSame($stored, Sectors::modulesFor('insurance_broker'));
        $this->assertArrayNotHasKey('sales', $stored);
        $this->assertArrayNotHasKey('accounting', $stored);
    }

    // ─────────────────────────────────────────────────────────── signup ──

    public function test_signing_up_with_a_sector_switches_the_right_modules(): void
    {
        $company = $this->register('insurance_broker');

        $this->assertSame('insurance_broker', $company->sector);

        $enabled = Modules::enabledFor($company);

        $this->assertContains('insurance', $enabled);
        $this->assertContains('sales', $enabled);
        $this->assertNotContains('products', $enabled);
        $this->assertNotContains('manufacturing', $enabled);
        $this->assertNotContains('estate', $enabled);
    }

    public function test_skipping_the_sector_yields_todays_defaults(): void
    {
        $company = $this->register();

        $this->assertSame(Sectors::EVERYTHING, $company->sector);
        // No departures stored: exactly the pre-sector behaviour.
        $this->assertSame([], (array) $company->modules);

        $enabled = Modules::enabledFor($company);
        $this->assertContains('sales', $enabled);
        $this->assertContains('products', $enabled);
        $this->assertNotContains('insurance', $enabled);
    }

    public function test_an_unknown_sector_falls_back_to_everything(): void
    {
        $company = $this->register('cryptocurrency-exchange');

        $this->assertSame(Sectors::EVERYTHING, $company->sector);
        $this->assertSame([], (array) $company->modules);
    }

    // ─────────────────────────────────────────────── the first-run card ──

    public function test_the_dashboard_shows_a_dismissible_first_run_card(): void
    {
        $this->register('logistics');

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Transporter & logistics')
            ->assertSee('Transport & shipments')
            ->assertSee('Settings');

        Livewire::test(Dashboard::class)->call('dismissWelcome');

        $this->get(route('dashboard'))->assertDontSee('Transporter & logistics');
    }

    public function test_the_card_does_not_show_for_an_ordinary_visit(): void
    {
        $owner = User::factory()->create();
        $company = $this->makeCompany($owner);
        $owner->forceFill(['current_company_id' => $company->id])->save();

        $this->actingAs($owner)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('switched on for');
    }

    // ─────────────────────────────── settings stays the source of truth ──

    public function test_the_sector_is_never_reapplied_after_signup(): void
    {
        $company = $this->register('distribution');
        $owner = User::where('id', $company->owner_id)->firstOrFail();
        app(CurrentCompany::class)->set($company);

        $this->assertContains('orders', Modules::enabledFor($company));

        // The owner disagrees with the sector and switches orders off.
        Livewire::actingAs($owner)
            ->test(SettingsIndex::class)
            ->call('toggleModule', 'orders');

        Modules::flush();
        $this->assertNotContains('orders', Modules::enabledFor($company->fresh()));

        // Nothing about the sector column re-imposes the sector's set: the
        // stored slug is a label and a reset target, never a live rule.
        $company->fresh()->forceFill(['sector' => 'distribution'])->save();
        Modules::flush();

        $this->assertNotContains('orders', Modules::enabledFor($company->fresh()));
    }

    public function test_reset_returns_the_modules_to_the_sector_defaults(): void
    {
        $company = $this->register('retail');
        $owner = User::where('id', $company->owner_id)->firstOrFail();
        app(CurrentCompany::class)->set($company);

        Livewire::actingAs($owner)
            ->test(SettingsIndex::class)
            ->call('toggleModule', 'orders');

        Modules::flush();
        $this->assertNotContains('orders', Modules::enabledFor($company->fresh()));

        Livewire::actingAs($owner)
            ->test(SettingsIndex::class)
            ->call('resetModulesToSector');

        Modules::flush();
        $this->assertContains('orders', Modules::enabledFor($company->fresh()));
        $this->assertSame(Sectors::modulesFor('retail'), $company->fresh()->modules);
    }

    public function test_reset_is_gated_on_settings_update(): void
    {
        $company = $this->register('retail');
        app(CurrentCompany::class)->set($company);

        $cashier = User::factory()->create();
        $this->joinCompany($company, $cashier, 'cashier');

        Livewire::actingAs($cashier)
            ->test(SettingsIndex::class)
            ->call('resetModulesToSector')
            ->assertForbidden();
    }

    // ────────────────────────────────────────────────── the other doors ──

    public function test_creating_a_second_business_can_pick_a_sector(): void
    {
        $owner = User::factory()->create();
        $first = $this->makeCompany($owner);
        $owner->forceFill(['current_company_id' => $first->id])->save();
        app(CurrentCompany::class)->set($first);

        Livewire::actingAs($owner)
            ->test(Companies::class)
            ->set('newName', 'Second Estate Sarl')
            ->set('newCurrency', 'XAF')
            ->set('newSector', 'estate')
            ->call('createCompany')
            ->assertHasNoErrors();

        $second = Company::where('name', 'Second Estate Sarl')->firstOrFail();

        $this->assertSame('estate', $second->sector);
        $this->assertContains('estate', Modules::enabledFor($second));
        $this->assertNotContains('products', Modules::enabledFor($second));
        // The first business is untouched.
        $this->assertNull($first->fresh()->sector);
    }

    public function test_the_demo_provisioner_can_apply_a_sector(): void
    {
        $result = app(DemoAccountProvisioner::class)->provision(
            'Demo Owner', 'demo-sector@example.com', 'Demo Clinic', null, 'health'
        );

        $company = $result['company']->fresh();

        $this->assertSame('health', $company->sector);
        $this->assertNotContains('deals', Modules::enabledFor($company));
        $this->assertContains('products', Modules::enabledFor($company));
    }
}
