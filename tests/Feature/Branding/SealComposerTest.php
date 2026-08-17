<?php

namespace Tests\Feature\Branding;

use App\Services\SealComposer;
use App\Support\SealCatalog;
use DOMDocument;
use Tests\TestCase;

/**
 * "Design 6 variant seal-like watermark templates... industry standard
 * like big companies... but not copy or duplicate." Six generic circular
 * seal designs, generated from the company's own name/registration text
 * rather than any specific real seal's artwork — see SealComposer's
 * docblock for what each design draws from.
 */
class SealComposerTest extends TestCase
{
    public function test_every_catalogued_design_renders_well_formed_svg(): void
    {
        foreach (SealCatalog::keys() as $design) {
            $svg = app(SealComposer::class)->render('Acme Trading Ltd', 'RC 12345', $design);

            $doc = new DOMDocument;
            $loaded = $doc->loadXML($svg);

            $this->assertTrue($loaded, "Design [{$design}] did not produce valid XML.");
            $this->assertSame('svg', $doc->documentElement->tagName);
        }
    }

    public function test_the_six_documented_designs_all_exist(): void
    {
        $this->assertCount(6, SealCatalog::designs());
    }

    public function test_the_company_name_appears_as_ring_text(): void
    {
        $svg = app(SealComposer::class)->render('Acme Trading Ltd', '', 'starburst');

        $this->assertStringContainsString('ACME TRADING LTD', $svg);
    }

    public function test_the_bottom_registration_text_is_optional(): void
    {
        $withReg = app(SealComposer::class)->render('Acme', 'RC 12345', 'starburst');
        $withoutReg = app(SealComposer::class)->render('Acme', '', 'starburst');

        $this->assertStringContainsString('RC 12345', $withReg);
        $this->assertStringNotContainsString('Bottom">', $withoutReg); // no bottom text element emitted at all
    }

    public function test_an_unknown_design_falls_back_to_starburst_rather_than_erroring(): void
    {
        $svg = app(SealComposer::class)->render('Acme', '', 'no-such-design');

        $doc = new DOMDocument;
        $this->assertTrue($doc->loadXML($svg));
    }

    public function test_the_brand_colour_is_used_throughout(): void
    {
        $svg = app(SealComposer::class)->render('Acme', 'RC 1', 'shield', '#16a34a');

        $this->assertStringContainsString('#16a34a', $svg);
    }

    public function test_untrusted_company_text_is_escaped_not_executed(): void
    {
        $svg = app(SealComposer::class)->render('<script>alert(1)</script>', '', 'monogram');

        $this->assertStringNotContainsString('<script>', $svg);
    }
}
