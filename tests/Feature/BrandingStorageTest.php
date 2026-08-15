<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\BrandDefaults;
use App\Support\Colour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BrandingStorageTest extends TestCase
{
    use RefreshDatabase;

    /** There is no CompanyFactory in this codebase; companies are built by hand. */
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

    public function test_a_company_with_no_branding_gets_the_platform_default(): void
    {
        $company = $this->company(['branding' => null]);

        $this->assertSame(BrandDefaults::inputs(), $company->brandingInputs());
        $this->assertSame('#1d4ed8', $company->palette()['light']['--color-brand']);
    }

    public function test_partial_branding_is_completed_by_defaults(): void
    {
        $company = $this->company(['branding' => ['primary' => '#1db954']]);

        $inputs = $company->brandingInputs();

        $this->assertSame('#1db954', $inputs['primary']);
        $this->assertSame('comfortable', $inputs['density'], 'an unset key should fall back');
        $this->assertSame('cool', $inputs['neutral']);
    }

    public function test_branding_changes_the_derived_palette(): void
    {
        $company = $this->company(['branding' => ['primary' => '#1db954']]);

        $brand = $company->palette()['light']['--color-brand'];

        $this->assertNotSame('#1d4ed8', $brand);
        $this->assertGreaterThanOrEqual(4.5, Colour::contrast($brand, '#eef2f7'));

        // Still recognisably the green that was asked for.
        $this->assertEqualsWithDelta(
            Colour::toOklch(Colour::fromHex('#1db954'))[2],
            Colour::toOklch(Colour::fromHex($brand))[2],
            2.0,
        );
    }

    /** The cache key includes the inputs, so an edit must take effect at once. */
    public function test_editing_branding_invalidates_the_cached_palette(): void
    {
        $company = $this->company(['branding' => ['primary' => '#1db954']]);

        $before = $company->palette()['light']['--color-brand'];

        $company->update(['branding' => ['primary' => '#4a154b']]);

        $this->assertNotSame($before, $company->fresh()->palette()['light']['--color-brand']);
    }

    /**
     * The reason print templates inherit branding without being edited: they
     * ask for a brand token and now get one from the derived palette.
     */
    public function test_brand_tokens_resolve_through_the_palette(): void
    {
        $company = $this->company([
            'brand_tokens' => null,
            'branding' => ['primary' => '#1db954'],
        ]);

        $primary = $company->brandToken('primary', '#2563eb');

        $this->assertNotSame('#2563eb', $primary, 'the fallback was used instead of the palette');
        $this->assertGreaterThanOrEqual(4.5, Colour::contrast($primary, '#ffffff'), 'print primary must carry white text');
    }

    /** A business that pinned a stationery colour keeps it. */
    public function test_an_explicit_brand_token_still_wins(): void
    {
        $company = $this->company([
            'brand_tokens' => ['primary' => '#ff0000'],
            'branding' => ['primary' => '#1db954'],
        ]);

        $this->assertSame('#ff0000', $company->brandToken('primary', '#2563eb'));
    }

    public function test_an_unknown_token_falls_through_to_the_caller_default(): void
    {
        $company = $this->company(['brand_tokens' => null, 'branding' => null]);

        $this->assertSame('#123456', $company->brandToken('no-such-token', '#123456'));
    }

    public function test_one_companys_branding_does_not_leak_into_another(): void
    {
        $a = $this->company(['branding' => ['primary' => '#1db954']]);
        $b = $this->company(['branding' => ['primary' => '#ff385c']]);

        $this->assertNotSame(
            $a->palette()['light']['--color-brand'],
            $b->palette()['light']['--color-brand'],
        );
    }
}
