<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\BrandPalette;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrandingDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function company(array $attributes = []): Company
    {
        $owner = User::factory()->create();

        return Company::create(array_merge([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ], $attributes));
    }

    protected function render(?Company $company): string
    {
        app(CurrentCompany::class)->set($company);

        return Blade::render('<x-branding.styles />');
    }

    public function test_it_emits_a_root_block_and_a_dark_block(): void
    {
        $css = $this->render($this->company());

        $this->assertStringContainsString(':root{', $css);
        $this->assertStringContainsString('.dark{', $css);
        $this->assertStringContainsString('--color-brand:', $css);
        $this->assertStringContainsString('--spacing:', $css);
        $this->assertStringContainsString('--radius-card:', $css);
    }

    public function test_a_companys_seed_reaches_the_stylesheet(): void
    {
        $css = $this->render($this->company(['branding' => ['primary' => '#4a154b']]));

        // #4a154b already clears AA on canvas, so it survives derivation intact
        // and should appear verbatim.
        $this->assertStringContainsString('#4a154b', $css);
    }

    public function test_one_companys_palette_never_appears_in_anothers(): void
    {
        $green = $this->render($this->company(['branding' => ['primary' => '#1db954']]));
        $aubergine = $this->render($this->company(['branding' => ['primary' => '#4a154b']]));

        $this->assertStringNotContainsString('#4a154b', $green);
        $this->assertNotSame($green, $aubergine);
    }

    public function test_no_company_still_renders_the_platform_default(): void
    {
        $css = $this->render(null);

        $this->assertStringContainsString('#1d4ed8', $css);
    }

    /**
     * The values land inside a <style> block, where Blade's escaping does
     * nothing useful — a brace would simply close the rule. Anything that is
     * not a colour, a length or a var() reference must be dropped.
     */
    public function test_a_hostile_value_cannot_break_out_of_the_style_block(): void
    {
        $css = Blade::render('<x-branding.styles :palette="$palette" />', [
            'palette' => [
                'root' => [
                    '--radius-card' => '1rem}</style><script>alert(1)</script><style>{',
                    '--spacing' => '0.25rem',
                ],
                'light' => ['--color-brand' => 'red;background:url(//evil)'],
                'dark' => [],
            ],
        ]);

        $this->assertStringNotContainsString('<script', $css);
        $this->assertStringNotContainsString('</style>', substr($css, 0, -8));
        $this->assertStringNotContainsString('evil', $css);

        // The legitimate value beside it still survives.
        $this->assertStringContainsString('--spacing:0.25rem', $css);
    }

    public function test_a_hostile_property_name_is_dropped(): void
    {
        $css = Blade::render('<x-branding.styles :palette="$palette" />', [
            'palette' => [
                'root' => ['--x}body{display:none' => '#fff', '--radius-card' => '4px'],
                'light' => [],
                'dark' => [],
            ],
        ]);

        $this->assertStringNotContainsString('body{display:none', $css);
        $this->assertStringContainsString('--radius-card:4px', $css);
    }

    public function test_the_dashboard_carries_the_branding_block(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $owner = User::factory()->create();
        $company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            'branding' => ['primary' => '#4a154b'],
        ]);

        $this->joinCompany($company, $owner);
        $owner->forceFill(['current_company_id' => $company->id])->save();

        // "/" is the dashboard when signed in and the landing page when not.
        $this->actingAs($owner)
            ->get('/')
            ->assertOk()
            ->assertSee('opes-branding', false)
            ->assertSee('#4a154b', false);
    }

    public function test_the_landing_page_renders_with_the_platform_palette(): void
    {
        $this->get('/')->assertOk()->assertSee('opes-branding', false);
    }

    public function test_the_emitted_css_has_no_line_breaks_that_could_split_a_rule(): void
    {
        $css = $this->render($this->company());

        $this->assertSame(1, substr_count($css, '<style'));
        $this->assertSame(1, substr_count($css, '</style>'));
    }

    public function test_every_derived_token_survives_the_whitelist(): void
    {
        $palette = BrandPalette::derive(['primary' => '#1db954']);
        $css = $this->render($this->company(['branding' => ['primary' => '#1db954']]));

        // Every token the generator produces must be a shape the whitelist
        // accepts — otherwise the platform silently drops its own colours.
        foreach ($palette['light'] as $name => $value) {
            $this->assertStringContainsString("{$name}:{$value};", $css, "{$name} was dropped");
        }

        foreach ($palette['root'] as $name => $value) {
            $this->assertStringContainsString("{$name}:{$value};", $css, "{$name} was dropped");
        }
    }
}
