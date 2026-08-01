<?php

namespace Tests\Unit;

use App\Support\Vat;
use PHPUnit\Framework\TestCase;

/**
 * Cameroon's TVA is 19.25% — 17.5% plus 10% centimes additionnels communaux
 * levied on the tax itself. These check the arithmetic a tax inspector would
 * redo by hand.
 */
class VatTest extends TestCase
{
    /** @param array<int, array{quantity: float|string, unit_price: float|string}> $lines */
    protected function compute(array $lines, bool $inclusive = false, bool $registered = true): array
    {
        return Vat::compute($lines, 19.25, $registered, $inclusive, 'XAF');
    }

    public function test_tax_is_added_on_top_of_a_price_keyed_excluding_it(): void
    {
        $result = $this->compute([['quantity' => 1, 'unit_price' => 100000]]);

        $this->assertSame(100000.0, $result['subtotal']);
        $this->assertSame(19250.0, $result['tax_total']);
        $this->assertSame(119250.0, $result['total']);
    }

    public function test_tax_is_extracted_from_a_price_keyed_including_it(): void
    {
        $result = $this->compute([['quantity' => 1, 'unit_price' => 119250]], inclusive: true);

        // The customer pays the shelf price, and the net is what is left of it.
        $this->assertSame(119250.0, $result['total']);
        $this->assertSame(100000.0, $result['subtotal']);
        $this->assertSame(19250.0, $result['tax_total']);
    }

    public function test_extracting_is_not_the_same_as_subtracting_the_rate(): void
    {
        $result = $this->compute([['quantity' => 1, 'unit_price' => 100000]], inclusive: true);

        // The trap: 100000 - 19.25% would give 80750 net and 19250 tax. The
        // correct net is 100000 / 1.1925, which is over three thousand francs
        // higher — and understating the net understates the tax owed.
        $this->assertNotSame(80750.0, $result['subtotal']);
        $this->assertSame(83857.0, $result['subtotal']);
        $this->assertSame(16143.0, $result['tax_total']);
    }

    public function test_net_and_tax_always_reconstitute_the_gross_exactly(): void
    {
        foreach ([1, 7, 99, 1000, 12345, 999999] as $gross) {
            $result = $this->compute([['quantity' => 1, 'unit_price' => $gross]], inclusive: true);

            $this->assertSame(
                (float) $gross,
                $result['subtotal'] + $result['tax_total'],
                "Net plus tax did not add back to {$gross}.",
            );
        }
    }

    public function test_the_printed_column_sums_to_the_printed_total(): void
    {
        $result = $this->compute([
            ['quantity' => 3, 'unit_price' => 1333],
            ['quantity' => 7, 'unit_price' => 499],
            ['quantity' => 1, 'unit_price' => 20001],
        ]);

        $this->assertSame(
            array_sum(array_column($result['lines'], 'net')),
            $result['subtotal'],
        );
        $this->assertSame(
            array_sum(array_column($result['lines'], 'tax')),
            $result['tax_total'],
        );
    }

    public function test_a_business_that_is_not_registered_charges_no_tax(): void
    {
        $result = $this->compute([['quantity' => 2, 'unit_price' => 50000]], registered: false);

        $this->assertFalse($result['applies']);
        $this->assertSame(0.0, $result['tax_total']);
        $this->assertSame(100000.0, $result['subtotal']);
        $this->assertSame(100000.0, $result['total']);
    }

    public function test_fcfa_amounts_stay_whole_francs(): void
    {
        $result = $this->compute([['quantity' => 1, 'unit_price' => 3333]]);

        foreach (['subtotal', 'tax_total', 'total'] as $key) {
            $this->assertSame(
                round($result[$key]),
                $result[$key],
                "{$key} carried a fraction of a franc.",
            );
        }
    }

    public function test_a_currency_with_a_minor_unit_keeps_its_centimes(): void
    {
        $result = Vat::compute([['quantity' => 1, 'unit_price' => 100]], 19.25, true, false, 'USD');

        $this->assertSame(19.25, $result['tax_total']);
        $this->assertSame(119.25, $result['total']);
    }

    public function test_the_unit_price_is_derived_from_the_line_not_the_other_way_round(): void
    {
        // 1000 TTC over three units: the net line is 839, which no whole-franc
        // unit price multiplies back to. The line total is what must be right.
        $result = $this->compute([['quantity' => 3, 'unit_price' => 1000]], inclusive: true);

        $this->assertSame(3000.0, $result['total']);
        $this->assertSame(2516.0, $result['subtotal']);
        $this->assertSame(484.0, $result['tax_total']);
        $this->assertSame(838.67, $result['lines'][0]['unit_net']);
    }

    public function test_a_zero_quantity_line_does_not_divide_by_zero(): void
    {
        $result = $this->compute([['quantity' => 0, 'unit_price' => 5000]]);

        $this->assertSame(0.0, $result['lines'][0]['unit_net']);
        $this->assertSame(0.0, $result['total']);
    }
}
