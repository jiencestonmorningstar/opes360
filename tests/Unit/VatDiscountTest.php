<?php

namespace Tests\Unit;

use App\Support\Vat;
use PHPUnit\Framework\TestCase;

/**
 * A discount reduces the taxable base.
 *
 * TVA is owed on what was actually charged. Computing it on the pre-discount
 * figure hands the DGI money that was never collected, on every discounted
 * invoice — which is the whole reason this lives in one tested function.
 */
class VatDiscountTest extends TestCase
{
    /** @return array<int, array{quantity: float, unit_price: float}> */
    protected function lines(float $amount = 100000): array
    {
        return [['quantity' => 1, 'unit_price' => $amount]];
    }

    public function test_no_discount_leaves_the_totals_alone(): void
    {
        $result = Vat::compute($this->lines(), 19.25, true, false, 'XAF');

        $this->assertSame(0.0, $result['discount_total']);
        $this->assertSame(100000.0, $result['subtotal']);
        $this->assertSame(119250.0, $result['total']);
    }

    /**
     * The worked example from the spec. It lands on an exact half — 85,000 at
     * 19.25% is 16,362.5 — so the expected value is stated rather than
     * recomputed: PHP rounds half away from zero and gives 16,363.
     */
    public function test_tax_is_computed_on_the_discounted_base(): void
    {
        $result = Vat::compute($this->lines(), 19.25, true, false, 'XAF', 15.0);

        $this->assertSame(100000.0, $result['subtotal']);
        $this->assertSame(15000.0, $result['discount_total']);
        $this->assertSame(16363.0, $result['tax_total']);
        $this->assertSame(101363.0, $result['total']);
    }

    public function test_the_discount_applies_without_vat_registration(): void
    {
        $result = Vat::compute($this->lines(), 0, false, false, 'XAF', 15.0);

        $this->assertSame(15000.0, $result['discount_total']);
        $this->assertSame(0.0, $result['tax_total']);
        $this->assertSame(85000.0, $result['total']);
    }

    /**
     * When prices are keyed TTC the discount comes off the gross, and net and
     * tax are re-extracted from what is left — otherwise the parts stop adding
     * up to the whole.
     *
     * 119 250 TTC at 19.25% is 100 000 net. A 10% discount takes the gross to
     * 107 325, which re-extracts to 90 000 net and 17 325 tax — so the discount
     * is the 10 000 taken off the net (100 000 - 90 000), not a naive 10% of
     * the gross. Pinning discount_total and tax_total individually, rather
     * than only the reconciliation identity below, matters because that
     * identity holds even if the tax were wrongly computed on the
     * pre-discount base: total is set independently of the split, so a bug in
     * where newNet is extracted from would still balance against itself.
     */
    public function test_a_tax_inclusive_price_discounts_the_gross(): void
    {
        $result = Vat::compute($this->lines(119250), 19.25, true, true, 'XAF', 10.0);

        $this->assertSame(100000.0, $result['subtotal']);
        $this->assertSame(10000.0, $result['discount_total']);
        $this->assertSame(17325.0, $result['tax_total']);
        $this->assertSame(107325.0, $result['total']);
        $this->assertSame($result['total'], round($result['subtotal'] - $result['discount_total'] + $result['tax_total'], 0));
    }

    public function test_net_plus_tax_equals_gross_with_a_discount(): void
    {
        foreach ([5.0, 12.5, 33.3, 99.0] as $percent) {
            $r = Vat::compute($this->lines(87654), 19.25, true, false, 'XAF', $percent);

            $this->assertSame(
                $r['total'],
                round($r['subtotal'] - $r['discount_total'] + $r['tax_total'], 0),
                "parts must add up at {$percent}%"
            );
        }
    }

    public function test_xaf_totals_stay_whole_francs(): void
    {
        $r = Vat::compute($this->lines(33333), 19.25, true, false, 'XAF', 7.0);

        foreach (['subtotal', 'discount_total', 'tax_total', 'total'] as $key) {
            $this->assertSame(round($r[$key]), $r[$key], "{$key} must be whole");
        }
    }

    public function test_a_discount_of_a_hundred_percent_leaves_nothing_owing(): void
    {
        $r = Vat::compute($this->lines(), 19.25, true, false, 'XAF', 100.0);

        $this->assertSame(100000.0, $r['discount_total']);
        $this->assertSame(0.0, $r['total']);
    }

    /** A nonsense percentage must not invert the invoice. */
    public function test_the_discount_is_clamped_to_a_sane_range(): void
    {
        $this->assertSame(0.0, Vat::compute($this->lines(), 0, false, false, 'XAF', -20.0)['discount_total']);
        $this->assertSame(100000.0, Vat::compute($this->lines(), 0, false, false, 'XAF', 250.0)['discount_total']);
    }
}
