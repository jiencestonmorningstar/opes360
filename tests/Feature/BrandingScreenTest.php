<?php

namespace Tests\Feature;

use App\Livewire\Business\Branding;
use App\Models\Company;
use App\Models\User;
use App\Models\Role;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingScreenTest extends TestCase
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
    }

    public function test_the_screen_renders(): void
    {
        $this->actingAs($this->owner)
            ->get(route('business.branding'))
            ->assertOk()
            ->assertSee('Branding')
            ->assertSee('Readability');
    }

    public function test_it_opens_on_the_companys_current_branding(): void
    {
        $this->company->forceFill(['branding' => ['primary' => '#4a154b', 'radius' => 'pill']])->save();

        Livewire::actingAs($this->owner)
            ->test(Branding::class)
            ->assertSet('primary', '#4a154b')
            ->assertSet('radius', 'pill')
            // Unset keys still arrive filled in from the platform default.
            ->assertSet('density', 'comfortable');
    }

    public function test_saving_persists_the_inputs(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Branding::class)
            ->set('primary', '#1db954')
            ->set('radius', 'sharp')
            ->set('density', 'compact')
            ->call('save');

        $branding = $this->company->fresh()->branding;

        $this->assertSame('#1db954', $branding['primary']);
        $this->assertSame('sharp', $branding['radius']);
        $this->assertSame('compact', $branding['density']);
    }

    public function test_a_saved_colour_reaches_the_next_page_load(): void
    {
        Livewire::actingAs($this->owner)->test(Branding::class)->set('primary', '#4a154b')->call('save');

        $this->actingAs($this->owner)->get('/')->assertOk()->assertSee('#4a154b', false);
    }

    public function test_an_invalid_colour_is_refused(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Branding::class)
            ->set('primary', 'chartreuse')
            ->call('save')
            ->assertHasErrors(['primary']);

        $this->assertNull($this->company->fresh()->branding);
    }

    public function test_an_unknown_option_is_refused(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Branding::class)
            ->set('radius', 'bouncy')
            ->call('save')
            ->assertHasErrors(['radius']);
    }

    public function test_glass_strength_is_bounded(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Branding::class)
            ->set('skin', 'glass')
            ->set('glass_strength', 4.0)
            ->call('save')
            ->assertHasErrors(['glass_strength']);
    }

    public function test_reset_clears_the_branding(): void
    {
        $this->company->forceFill(['branding' => ['primary' => '#1db954']])->save();

        Livewire::actingAs($this->owner)->test(Branding::class)->call('resetToDefault');

        $this->assertNull($this->company->fresh()->branding);
    }

    /** The preview must reflect what is on screen, not what is saved. */
    public function test_the_preview_follows_unsaved_changes(): void
    {
        $component = Livewire::actingAs($this->owner)->test(Branding::class)->set('primary', '#1db954');

        $brand = $component->instance()->previewPalette()['light']['--color-brand'];

        $this->assertNotSame('#1d4ed8', $brand, 'the preview is showing the saved palette, not the edited one');
        $this->assertNull($this->company->fresh()->branding, 'nothing should have been saved yet');
    }

    /** The number beside each role is the whole point of the screen. */
    public function test_the_contrast_report_always_passes(): void
    {
        foreach (['#1db954', '#ffff00', '#ffffff', '#000000', '#ff385c'] as $seed) {
            $rows = Livewire::actingAs($this->owner)
                ->test(Branding::class)
                ->set('primary', $seed)
                ->instance()
                ->contrastReport();

            foreach ($rows as $row) {
                $this->assertGreaterThanOrEqual(
                    4.5,
                    $row['ratio'],
                    "{$row['label']} scored {$row['ratio']} for seed {$seed}",
                );
            }
        }
    }

    public function test_a_preset_fills_both_colours(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Branding::class)
            ->call('applyPreset', '#4a154b', '#b91c60')
            ->assertSet('primary', '#4a154b')
            ->assertSet('secondary', '#b91c60');
    }

    public function test_a_cashier_cannot_reach_the_screen(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($cashier)->get(route('business.branding'))->assertForbidden();
    }

    public function test_a_cashier_cannot_save_by_calling_the_component(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Livewire::actingAs($cashier)->test(Branding::class)->assertForbidden();
    }
}
