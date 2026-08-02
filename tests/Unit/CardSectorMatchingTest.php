<?php

namespace Tests\Unit;

use App\Support\CardCatalog;
use PHPUnit\Framework\TestCase;

/**
 * Which card designs a business is offered first, given its trade.
 *
 * The mapping is keyword-based, and keywords hidden inside longer words are the
 * failure mode: matching "sport" anywhere in the string sent every haulier in
 * "Transport & Logistics" to the gym designs, and "wellness" pulled beauty
 * salons there too. Both were live and neither was visible without looking at
 * the recommendation for a specific industry.
 */
class CardSectorMatchingTest extends TestCase
{
    /**
     * @dataProvider industries
     */
    public function test_each_configured_industry_maps_where_a_person_would_expect(string $industry, ?string $expected): void
    {
        $this->assertSame($expected, CardCatalog::sectorFor($industry));
    }

    public static function industries(): array
    {
        return [
            // The one that was wrong: "tranSPORT".
            'transport is not a sport' => ['Transport & Logistics', 'Automotive'],
            // The other one: a spa is not a gym.
            'wellness is a salon' => ['Beauty & Wellness', 'Beauty & Salon'],
            // "hoSPITALity" is not a hospital.
            'hospitality is food' => ['Hospitality', 'Restaurant & Food'],

            'technology' => ['Technology', 'Technology & IT'],
            'healthcare' => ['Healthcare', 'Health & Pharmacy'],
            'construction' => ['Construction', 'Construction'],
            'education' => ['Education', 'Education'],
            'fashion' => ['Fashion', 'Fashion'],
            'food' => ['Food & Beverage', 'Restaurant & Food'],

            // Nothing plausible is better than something wrong: these fall
            // through to the universal designs.
            'retail' => ['Retail', null],
            'agriculture' => ['Agriculture', null],
            'manufacturing' => ['Manufacturing', null],
            'other' => ['Other', null],
        ];
    }

    /** Prefix stems still have to work, or French trade names stop matching. */
    public function test_word_initial_stems_still_match(): void
    {
        $this->assertSame('Health & Pharmacy', CardCatalog::sectorFor('Pharmacie du Centre'));
        $this->assertSame('Real Estate', CardCatalog::sectorFor('Agence Immobilière'));
        $this->assertSame('Beauty & Salon', CardCatalog::sectorFor('Salon de coiffure'));
        $this->assertSame('Accounting & Finance', CardCatalog::sectorFor('Cabinet de comptabilité'));
        $this->assertSame('Fitness & Sport', CardCatalog::sectorFor('Sport Club Douala'));
    }

    public function test_an_empty_or_unknown_industry_recommends_nothing(): void
    {
        $this->assertNull(CardCatalog::sectorFor(null));
        $this->assertNull(CardCatalog::sectorFor(''));
        $this->assertNull(CardCatalog::sectorFor('   '));
        $this->assertNull(CardCatalog::sectorFor('Zzzzz'));
    }

    /** Every sector a design claims must be one the matcher can actually return. */
    public function test_every_design_sits_in_a_real_sector(): void
    {
        $sectors = array_keys(CardCatalog::bySector());

        foreach (CardCatalog::designs() as $key => $design) {
            $this->assertContains(
                $design['sector'] ?? null,
                $sectors,
                "Design [{$key}] claims a sector nothing else knows about."
            );
        }
    }
}
