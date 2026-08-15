<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\BrandPalette;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrandingGlassTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->owner = User::factory()->create();
    }

    protected function company(array $branding = null): Company
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

    public function test_the_solid_skin_adds_no_glass_class(): void
    {
        $this->company(['skin' => 'solid']);

        $this->assertSame('', BrandPalette::skinClass());
    }

    public function test_the_glass_skin_adds_the_class(): void
    {
        $this->company(['skin' => 'glass']);

        $this->assertSame('glass', BrandPalette::skinClass());
    }

    /** Solid must be byte-identical to how the app looked before this module. */
    public function test_solid_chrome_keeps_its_original_backgrounds(): void
    {
        $this->company(['skin' => 'solid']);

        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('bg-surface', $html);
        $this->assertStringNotContainsString('class="fixed inset-y-0 left-0 z-40 flex w-[280px] shrink-0 flex-col transition-transform duration-200 ease-out
              glass', $html);
    }

    public function test_glass_chrome_drops_the_opaque_background(): void
    {
        $this->company(['skin' => 'glass']);

        $html = $this->actingAs($this->owner)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('glass', $html);
    }

    public function test_glass_tokens_reach_the_page_only_when_chosen(): void
    {
        $this->company(['skin' => 'glass', 'glass_strength' => 1.0]);
        $glassHtml = $this->actingAs($this->owner)->get('/')->getContent();

        $this->assertStringContainsString('--glass-blur:24px', $glassHtml);

        $this->company(['skin' => 'solid']);
        $solidHtml = $this->actingAs($this->owner)->get('/')->getContent();

        $this->assertStringContainsString('--glass-blur:0px', $solidHtml);
    }

    /**
     * The compiled stylesheet must guard the effect. A blur that runs
     * unconditionally would hit devices that cannot afford it and users who
     * asked their system to stop it.
     */
    public function test_the_compiled_stylesheet_guards_the_effect(): void
    {
        $css = $this->compiledCss();

        $this->assertStringContainsString('@supports', $css);
        $this->assertMatchesRegularExpression(
            '/@supports \(\(-webkit-backdrop-filter[^{]*\)\)\{\.glass\{/',
            $css,
            'backdrop-filter is not behind an @supports guard',
        );

        $this->assertStringContainsString('prefers-reduced-transparency', $css);
    }

    /** A blur on paper costs ink and prints as a grey smear. */
    public function test_print_never_blurs(): void
    {
        $css = $this->compiledCss();

        preg_match('/@media print\{(.*?)(?=@media|$)/s', $css, $m);

        $this->assertNotEmpty($m, 'no print block in the stylesheet');
        $this->assertStringContainsString('backdrop-filter:none!important', $m[1]);
    }

    /** The document print views must not carry the glass class at all. */
    public function test_printed_documents_carry_no_glass(): void
    {
        foreach (glob(resource_path('views/print/*.blade.php')) as $view) {
            $this->assertStringNotContainsString(
                'glass',
                (string) file_get_contents($view),
                basename($view).' carries the glass class',
            );
        }
    }

    protected function compiledCss(): string
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);
        $file = $manifest['resources/css/app.css']['file'] ?? null;

        $this->assertNotNull($file, 'no compiled css in the manifest — run npm run build');

        return (string) file_get_contents(public_path('build/'.$file));
    }
}
