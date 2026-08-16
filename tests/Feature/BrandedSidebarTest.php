<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\BrandDefaults;
use App\Support\BrandPalette;
use App\Support\Colour;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The navigation rail wears the business's brand colour.
 *
 * Which makes it the one place in the product where white text sits on a colour
 * the business chose, so the fill role — not the ink role, not the raw seed —
 * is the only safe source for it.
 */
class BrandedSidebarTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->owner = User::factory()->create();
    }

    protected function company(?array $branding = null): Company
    {
        $company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            'branding' => $branding,
        ]);

        $this->joinCompany($company, $this->owner);
        $this->owner->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        return $company;
    }

    public static function seeds(): array
    {
        return [
            'Spotify green' => ['#1db954'],
            'Airbnb red' => ['#ff385c'],
            'pure yellow' => ['#ffff00'],
            'pure white' => ['#ffffff'],
            'pure cyan' => ['#00ffff'],
            'aubergine' => ['#4a154b'],
        ];
    }

    /**
     * The guarantee that matters here. Every menu label on the rail is white,
     * so the rail's colour must carry white text whatever the business picked.
     */
    #[DataProvider('seeds')]
    public function test_the_rail_always_carries_white_text(string $seed): void
    {
        foreach (['light', 'dark'] as $mode) {
            $bg = BrandPalette::derive(
                array_merge(BrandDefaults::inputs(), ['primary' => $seed])
            )[$mode]['--sidebar-bg'];

            $this->assertGreaterThanOrEqual(
                4.5,
                Colour::contrast($bg, '#ffffff'),
                "white menu labels on the {$mode} rail for seed {$seed}",
            );
        }
    }

    public function test_the_rail_uses_the_fill_role_not_the_raw_seed(): void
    {
        $palette = BrandPalette::derive(
            array_merge(BrandDefaults::inputs(), ['primary' => '#1db954'])
        )['light'];

        $this->assertSame($palette['--color-fill-brand'], $palette['--sidebar-bg']);
        $this->assertNotSame('#1db954', $palette['--sidebar-bg'], 'the raw seed cannot carry white text');
    }

    public function test_the_rail_is_painted_from_the_token_on_a_real_page(): void
    {
        $this->company(['primary' => '#4a154b']);

        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('sidebar-surface', $html);
        $this->assertStringContainsString('--sidebar-bg:#4a154b', $html);
    }

    /** The old neutral rail must be gone, not merely overpainted. */
    public function test_the_rail_no_longer_uses_the_neutral_surface_tokens(): void
    {
        $markup = (string) file_get_contents(resource_path('views/partials/sidebar.blade.php'));

        foreach (['bg-surface', 'lg:bg-canvas', 'text-ink-2', 'text-muted'] as $stale) {
            $this->assertStringNotContainsString($stale, $markup, "the rail still uses {$stale}");
        }
    }

    /** Glass over the brand colour, not glass instead of it. */
    public function test_the_glass_rail_stays_the_brand_colour(): void
    {
        $bg = BrandPalette::derive(array_merge(BrandDefaults::inputs(), [
            'primary' => '#1db954', 'skin' => 'glass', 'glass_strength' => 0.5,
        ]))['light']['--sidebar-bg'];

        $this->assertStringStartsWith('rgb(', $bg, 'the glass rail should be translucent');
        $this->assertStringNotContainsString('255 255 255', $bg, 'the glass rail went white instead of brand');
    }

    public function test_the_glass_rail_uses_blur_without_its_own_background(): void
    {
        $this->company(['skin' => 'glass']);

        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        // glass-blur, not glass: the plain glass class sets a background that
        // would paint over the brand colour.
        $this->assertMatchesRegularExpression('/sidebar-surface glass-blur/', $html);
    }

    /** Reduced transparency must return an opaque rail, not a half-lit one. */
    public function test_reduced_transparency_makes_the_rail_opaque_again(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $css = (string) file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));

        $this->assertStringContainsString('prefers-reduced-transparency', $css);
        $this->assertStringContainsString(
            '.sidebar-surface{background-color:var(--color-fill-brand)!important}',
            $css,
            'the rail stays translucent for somebody who asked their system to stop exactly that',
        );
    }
}
