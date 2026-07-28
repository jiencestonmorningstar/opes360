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
    ];

    public static function symbol(?string $currency): string
    {
        $currency = $currency ?: 'USD';

        return self::SYMBOLS[strtoupper($currency)] ?? strtoupper($currency).' ';
    }

    /**
     * Null currency falls back to USD: a model created without an explicit
     * currency holds null until the database default is read back, and a
     * formatting helper must never turn that into a fatal.
     */
    public static function format(float|int|string|null $amount, ?string $currency = 'USD', bool $decimals = true): string
    {
        return self::symbol($currency).number_format((float) $amount, $decimals ? 2 : 0);
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
