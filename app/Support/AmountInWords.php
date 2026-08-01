<?php

namespace App\Support;

/**
 * "Arrêtée la présente facture à la somme de …" — the amount written out in
 * French words, which OHADA-country invoices and receipts are expected to
 * carry alongside the figures.
 *
 * It exists to make tampering obvious: altering a digit is easy, altering the
 * digits *and* the sentence agreeing with them is not.
 *
 * French number words have three irregularities this has to respect, and all
 * three are the kind a naive implementation gets wrong:
 *
 *   - 70 and 90 are built as 60+10 and 80+10 (soixante-dix, quatre-vingt-dix),
 *     so the tens digit alone does not determine the word.
 *   - "quatre-vingts" takes an s only when nothing follows it: quatre-vingts,
 *     but quatre-vingt-un.
 *   - "cent" and "mille" pluralise differently — cents takes an s when
 *     multiplied and final, mille never does.
 */
class AmountInWords
{
    protected const UNITS = [
        0 => 'zéro', 1 => 'un', 2 => 'deux', 3 => 'trois', 4 => 'quatre',
        5 => 'cinq', 6 => 'six', 7 => 'sept', 8 => 'huit', 9 => 'neuf',
        10 => 'dix', 11 => 'onze', 12 => 'douze', 13 => 'treize',
        14 => 'quatorze', 15 => 'quinze', 16 => 'seize',
    ];

    protected const TENS = [
        2 => 'vingt', 3 => 'trente', 4 => 'quarante',
        5 => 'cinquante', 6 => 'soixante', 8 => 'quatre-vingt',
    ];

    /**
     * The full sentence for a document footer, e.g.
     * "cent dix-neuf mille deux cent cinquante francs CFA".
     */
    public static function forCurrency(float $amount, ?string $currency = 'XAF'): string
    {
        $currency = strtoupper((string) ($currency ?: 'XAF'));
        $decimals = Vat::decimalsFor($currency);

        $amount = round($amount, $decimals);
        $whole = (int) floor($amount);
        $fraction = (int) round(($amount - $whole) * (10 ** $decimals));

        $words = self::convert($whole).' '.self::unitName($currency, $whole);

        if ($decimals > 0 && $fraction > 0) {
            $words .= ' et '.self::convert($fraction).' centime'.($fraction > 1 ? 's' : '');
        }

        return $words;
    }

    protected static function unitName(string $currency, int $whole): string
    {
        return match ($currency) {
            'XAF', 'XOF' => 'francs CFA',
            'EUR' => 'euro'.($whole > 1 ? 's' : ''),
            'USD' => 'dollar'.($whole > 1 ? 's' : ''),
            'GBP' => 'livre'.($whole > 1 ? 's' : '').' sterling',
            'NGN' => 'naira',
            default => $currency,
        };
    }

    public static function convert(int $number): string
    {
        if ($number < 0) {
            return 'moins '.self::convert(-$number);
        }

        if ($number < 17) {
            return self::UNITS[$number];
        }

        if ($number < 100) {
            return self::underHundred($number);
        }

        if ($number < 1000) {
            return self::underThousand($number);
        }

        foreach ([1_000_000_000 => 'milliard', 1_000_000 => 'million', 1000 => 'mille'] as $base => $name) {
            if ($number >= $base) {
                return self::scale($number, $base, $name);
            }
        }

        return (string) $number;
    }

    protected static function underHundred(int $number): string
    {
        $tens = intdiv($number, 10);
        $unit = $number % 10;

        // Seventeen to nineteen are built on dix, and there is no "ten" entry
        // in the tens table to reach for — 10 to 16 are irregular words that
        // UNITS already holds outright.
        if ($tens === 1) {
            return 'dix-'.self::UNITS[$unit];
        }

        // Seventy and ninety are counted from sixty and eighty: soixante-dix,
        // quatre-vingt-onze. The tens digit alone does not name the word.
        if ($tens === 7 || $tens === 9) {
            $base = self::TENS[$tens - 1];
            $remainder = $number - ($tens === 7 ? 60 : 80);

            // Seventy-one is "soixante et onze", carrying the same "et" as
            // sixty-one. Ninety-one does not — the quatre-vingt series never
            // takes it, which is why this cannot key off the remainder alone.
            if ($remainder === 11 && $tens === 7) {
                return $base.' et onze';
            }

            return $base.'-'.self::convert($remainder);
        }

        $word = self::TENS[$tens];

        if ($unit === 0) {
            // quatre-vingts, but quatre-vingt-un: the s survives only when the
            // word ends the number.
            return $tens === 8 ? $word.'s' : $word;
        }

        // vingt et un, trente et un … but quatre-vingt-un takes no "et".
        if ($unit === 1 && $tens !== 8) {
            return $word.' et un';
        }

        return $word.'-'.self::UNITS[$unit];
    }

    protected static function underThousand(int $number): string
    {
        $hundreds = intdiv($number, 100);
        $remainder = $number % 100;

        $word = $hundreds === 1 ? 'cent' : self::UNITS[$hundreds].' cent';

        // deux cents, but deux cent cinquante — as with quatre-vingts, the
        // plural only survives at the end.
        if ($remainder === 0) {
            return $hundreds === 1 ? 'cent' : $word.'s';
        }

        return $word.' '.self::convert($remainder);
    }

    protected static function scale(int $number, int $base, string $name): string
    {
        $count = intdiv($number, $base);
        $remainder = $number % $base;

        // "mille" is invariable — deux mille, never deux milles — while
        // millions and milliards are nouns and take the plural.
        $prefix = $base === 1000
            ? ($count === 1 ? 'mille' : self::convert($count).' mille')
            : self::convert($count).' '.$name.($count > 1 ? 's' : '');

        return $remainder === 0 ? $prefix : $prefix.' '.self::convert($remainder);
    }
}
