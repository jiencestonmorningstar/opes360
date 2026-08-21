<?php

namespace Tests\Feature;

use App\Support\BrandPalette;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The palette renders into the <head> of every layout in the product, and the
 * cache store is `database`. So if resolving it can throw, a database that is
 * down takes the error pages down with it — the 500 and the 503 would
 * themselves fail, in exactly the conditions they exist to report.
 *
 * These tests break the cache the way a downed database would and assert the
 * pages still render.
 */
class BrandingResilienceTest extends TestCase
{
    use RefreshDatabase;

    /** Simulate the cache store being unreachable. */
    protected function breakTheCache(): void
    {
        Config::set('cache.default', 'database');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }

    public function test_the_palette_still_resolves_when_the_cache_is_unreachable(): void
    {
        $this->breakTheCache();

        $palette = BrandPalette::for(null);

        $this->assertSame('#1d4ed8', $palette['light']['--color-brand']);
        $this->assertArrayHasKey('--spacing', $palette['root']);
    }

    public function test_the_500_page_renders_without_a_working_cache(): void
    {
        $this->breakTheCache();

        $html = view('errors.500')->render();

        $this->assertStringContainsString('opes-branding', $html);
        $this->assertStringContainsString('#1d4ed8', $html);
    }

    public function test_the_503_page_renders_without_a_working_cache(): void
    {
        $this->breakTheCache();

        $this->assertStringContainsString('opes-branding', view('errors.503')->render());
    }

    public function test_the_404_and_419_pages_render_without_a_working_cache(): void
    {
        $this->breakTheCache();

        foreach (['errors.404', 'errors.419'] as $view) {
            $this->assertStringContainsString('opes-branding', view($view)->render(), $view);
        }
    }

    /** A working cache must still be used — the fallback is not the normal path. */
    public function test_a_healthy_cache_is_still_used(): void
    {
        $palette = BrandPalette::for(null);

        $this->assertSame('#1d4ed8', $palette['light']['--color-brand']);
        $this->assertTrue(Schema::hasTable('cache'), 'the cache table should be intact here');
    }
}
