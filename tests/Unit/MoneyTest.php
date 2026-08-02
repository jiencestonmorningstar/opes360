<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * Formatting money.
 *
 * Mostly about one rule: the CFA franc has no centime in circulation, so a
 * trailing ".00" on an XAF figure is not extra precision — it is three
 * characters of noise, and on a 390px phone it was clipping the dashboard's
 * stat cards.
 */
class MoneyTest extends TestCase
{
    public function test_a_franc_amount_carries_no_decimals(): void
    {
        $this->assertSame('FCFA1,250', Money::format(1250, 'XAF'));
        $this->assertSame('FCFA1,250', Money::format(1250.00, 'XAF'));
        $this->assertSame('CFA1,250', Money::format(1250, 'XOF'));
    }

    public function test_a_decimal_currency_keeps_its_decimals(): void
    {
        $this->assertSame('$1,250.00', Money::format(1250, 'USD'));
        $this->assertSame('€1,250.50', Money::format(1250.5, 'EUR'));
        $this->assertSame('₦1,250.00', Money::format(1250, 'NGN'));
    }

    /**
     * A caller can still force the compact form for a tight column. What it
     * cannot do is force centimes onto a currency that has none — asking for
     * them is a mistake, and honouring it would put a figure on screen that
     * does not exist in the market.
     */
    public function test_decimals_can_be_switched_off_but_not_invented(): void
    {
        $this->assertSame('$1,250', Money::format(1250, 'USD', false));
        $this->assertSame('FCFA1,250', Money::format(1250, 'XAF', false));
    }

    public function test_a_missing_currency_falls_back_rather_than_failing(): void
    {
        $this->assertSame('$0.00', Money::format(null, null));
        $this->assertSame('$12.00', Money::format(12, null));
    }

    public function test_an_unknown_currency_prints_its_code(): void
    {
        $this->assertSame('CAD 12.00', Money::format(12, 'CAD'));
    }

    public function test_the_minor_unit_rule_is_answerable_on_its_own(): void
    {
        $this->assertTrue(Money::hasNoMinorUnit('XAF'));
        $this->assertTrue(Money::hasNoMinorUnit('xof'));
        $this->assertFalse(Money::hasNoMinorUnit('USD'));
        $this->assertFalse(Money::hasNoMinorUnit(null));
    }

    public function test_the_compact_form_is_for_chart_axes(): void
    {
        $this->assertSame('FCFA1.3k', Money::compact(1250, 'XAF'));
        $this->assertSame('FCFA2.4M', Money::compact(2_400_000, 'XAF'));
        $this->assertSame('$820', Money::compact(820, 'USD'));
    }
}
