<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandPalette;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrandingApiTest extends TestCase
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

    /** Sanctum::actingAs defaults to no abilities, so they must be named. */
    protected function actingWithAbilities(array $abilities = ['*']): void
    {
        Sanctum::actingAs($this->owner, $abilities);
    }

    public function test_it_returns_the_inputs_the_palette_and_the_options(): void
    {
        $this->actingWithAbilities(['read']);

        $this->getJson('/api/v1/branding')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'inputs' => ['primary', 'secondary', 'neutral', 'radius', 'density', 'skin', 'glass_strength'],
                    'palette' => ['light', 'dark', 'root'],
                    'options' => ['neutral', 'radius', 'density', 'skin'],
                ],
            ])
            ->assertJsonPath('data.inputs.primary', '#1d4ed8');
    }

    /** A consumer must not have to reimplement the derivation to paint correctly. */
    public function test_the_returned_palette_matches_what_the_platform_renders(): void
    {
        $this->company->forceFill(['branding' => ['primary' => '#1db954']])->save();
        $this->actingWithAbilities(['read']);

        $this->getJson('/api/v1/branding')->assertOk()->assertJsonPath(
            'data.palette.light.--color-brand',
            BrandPalette::for($this->company->fresh())['light']['--color-brand'],
        );
    }

    public function test_updating_persists_and_returns_the_new_palette(): void
    {
        $this->actingWithAbilities(['write']);

        $this->putJson('/api/v1/branding', ['primary' => '#4a154b', 'radius' => 'pill'])
            ->assertOk()
            ->assertJsonPath('data.inputs.primary', '#4a154b')
            ->assertJsonPath('data.palette.root.--radius-card', '1.75rem');

        $this->assertSame('#4a154b', $this->company->fresh()->branding['primary']);
    }

    /** A partial update must not clobber the keys it did not mention. */
    public function test_a_partial_update_leaves_the_rest_alone(): void
    {
        $this->company->forceFill(['branding' => ['primary' => '#4a154b', 'density' => 'compact']])->save();
        $this->actingWithAbilities(['write']);

        $this->patchJson('/api/v1/branding', ['radius' => 'sharp'])->assertOk();

        $branding = $this->company->fresh()->branding;

        $this->assertSame('#4a154b', $branding['primary'], 'the colour was clobbered');
        $this->assertSame('compact', $branding['density'], 'the density was clobbered');
        $this->assertSame('sharp', $branding['radius']);
    }

    public function test_deleting_resets_to_the_platform_default(): void
    {
        $this->company->forceFill(['branding' => ['primary' => '#1db954']])->save();
        $this->actingWithAbilities(['write']);

        $this->deleteJson('/api/v1/branding')
            ->assertOk()
            ->assertJsonPath('data.inputs.primary', '#1d4ed8');

        $this->assertNull($this->company->fresh()->branding);
    }

    public function test_an_invalid_colour_is_rejected(): void
    {
        $this->actingWithAbilities(['write']);

        $this->putJson('/api/v1/branding', ['primary' => 'chartreuse'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['primary']);
    }

    public function test_an_unknown_option_is_rejected(): void
    {
        $this->actingWithAbilities(['write']);

        $this->putJson('/api/v1/branding', ['radius' => 'bouncy'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['radius']);
    }

    public function test_glass_strength_is_bounded(): void
    {
        $this->actingWithAbilities(['write']);

        $this->putJson('/api/v1/branding', ['glass_strength' => 7])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['glass_strength']);
    }

    /** A token narrower than its user must not be able to write. */
    public function test_a_read_only_token_cannot_update(): void
    {
        $this->actingWithAbilities(['read']);

        $this->putJson('/api/v1/branding', ['primary' => '#1db954'])->assertForbidden();
    }

    public function test_a_cashier_cannot_update_even_with_a_write_token(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($cashier, ['write']);

        $this->putJson('/api/v1/branding', ['primary' => '#1db954'])->assertForbidden();
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/branding')->assertUnauthorized();
    }
}
