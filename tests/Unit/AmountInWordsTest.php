<?php

namespace Tests\Unit;

use App\Support\AmountInWords;
use PHPUnit\Framework\TestCase;

/**
 * French number words, which invoices in OHADA countries carry in full.
 * The awkward cases are seventy/ninety, the plural of quatre-vingts and cents,
 * and the fact that mille never pluralises.
 */
class AmountInWordsTest extends TestCase
{
    public function test_the_small_numbers(): void
    {
        $this->assertSame('zéro', AmountInWords::convert(0));
        $this->assertSame('sept', AmountInWords::convert(7));
        $this->assertSame('seize', AmountInWords::convert(16));
        $this->assertSame('dix-sept', AmountInWords::convert(17));
    }

    public function test_seventy_and_ninety_are_counted_from_sixty_and_eighty(): void
    {
        $this->assertSame('soixante-dix', AmountInWords::convert(70));
        $this->assertSame('soixante et onze', AmountInWords::convert(71));
        $this->assertSame('soixante-quinze', AmountInWords::convert(75));
        $this->assertSame('quatre-vingt-dix', AmountInWords::convert(90));
        $this->assertSame('quatre-vingt-onze', AmountInWords::convert(91));
    }

    public function test_quatre_vingts_keeps_its_s_only_at_the_end(): void
    {
        $this->assertSame('quatre-vingts', AmountInWords::convert(80));
        $this->assertSame('quatre-vingt-un', AmountInWords::convert(81));
    }

    public function test_the_et_un_forms(): void
    {
        $this->assertSame('vingt et un', AmountInWords::convert(21));
        $this->assertSame('trente et un', AmountInWords::convert(31));
        // quatre-vingt-un is the exception that takes no "et".
        $this->assertSame('quatre-vingt-un', AmountInWords::convert(81));
    }

    public function test_cent_pluralises_only_when_final(): void
    {
        $this->assertSame('cent', AmountInWords::convert(100));
        $this->assertSame('cent un', AmountInWords::convert(101));
        $this->assertSame('deux cents', AmountInWords::convert(200));
        $this->assertSame('deux cent cinquante', AmountInWords::convert(250));
    }

    public function test_mille_never_pluralises(): void
    {
        $this->assertSame('mille', AmountInWords::convert(1000));
        $this->assertSame('deux mille', AmountInWords::convert(2000));
        $this->assertSame('dix mille', AmountInWords::convert(10000));
    }

    public function test_millions_and_milliards_do_pluralise(): void
    {
        $this->assertSame('un million', AmountInWords::convert(1000000));
        $this->assertSame('deux millions', AmountInWords::convert(2000000));
        $this->assertSame('trois milliards', AmountInWords::convert(3000000000));
    }

    public function test_a_realistic_invoice_total(): void
    {
        $this->assertSame(
            'cent dix-neuf mille deux cent cinquante francs CFA',
            AmountInWords::forCurrency(119250, 'XAF'),
        );
    }

    public function test_fcfa_carries_no_centimes(): void
    {
        // XAF has no minor unit, so nothing is appended even for a stray
        // fraction that survived an exchange-rate conversion.
        $this->assertSame('mille francs CFA', AmountInWords::forCurrency(1000.4, 'XAF'));
    }

    public function test_a_decimal_currency_spells_out_its_centimes(): void
    {
        $this->assertSame(
            'cent dix-neuf dollars et vingt-cinq centimes',
            AmountInWords::forCurrency(119.25, 'USD'),
        );
    }
}
