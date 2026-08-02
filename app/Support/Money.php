<?php

namespace App\Support;

class Money
{
    /** Symbols for the currencies the first launch markets use. */
    protected const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'NGN' => '₦',
        'GHS' => 'GH₵',
        'KES' => 'KSh',
        'ZAR' => 'R',
        'XOF' => 'CFA',
        'XAF' => 'FCFA',
    ];

    public static function symbol(?string $currency): string
    {
        $currency = $currency ?: 'USD';

        return self::SYMBOLS[strtoupper($currency)] ?? strtoupper($currency).' ';
    }

    /**
     * Currencies with no minor unit.
     *
     * The CFA franc has no centime in circulation, so "FCFA1,250.00" is not a
     * more precise figure than "FCFA1,250" — it is three characters of noise
     * on a phone, and it was clipping the dashboard's stat cards. The ISO 4217
     * minor unit for XAF and XOF is zero; this is that, not a display
     * preference.
     */
    protected const NO_MINOR_UNIT = ['XAF', 'XOF'];

    /**
     * Null currency falls back to USD: a model created without an explicit
     * currency holds null until the database default is read back, and a
     * formatting helper must never turn that into a fatal.
     *
     * `$decimals` left null asks the currency, which is almost always what a
     * caller means. Passing false forces the compact form for a tight column
     * whatever the currency is.
     */
    public static function format(float|int|string|null $amount, ?string $currency = 'USD', ?bool $decimals = null): string
    {
        $decimals ??= ! self::hasNoMinorUnit($currency);

        return self::symbol($currency).number_format((float) $amount, $decimals ? 2 : 0);
    }

    public static function hasNoMinorUnit(?string $currency): bool
    {
        return in_array(strtoupper($currency ?: 'USD'), self::NO_MINOR_UNIT, true);
    }

    /** Compact form for chart axes and tight columns: $1.3k, $2.4M. */
    public static function compact(float|int|string|null $amount, ?string $currency = 'USD'): string
    {
        $value = (float) $amount;
        $symbol = self::symbol($currency);

        return match (true) {
            abs($value) >= 1_000_000 => $symbol.round($value / 1_000_000, 1).'M',
            abs($value) >= 1_000 => $symbol.round($value / 1_000, 1).'k',
            default => $symbol.number_format($value, 0),
        };
    }

    /**
     * Percentage change, guarding the divide-by-zero case that a brand new
     * business hits on its first day.
     */
    public static function change(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }
}
