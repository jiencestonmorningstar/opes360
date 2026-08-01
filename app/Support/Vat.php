<?php

namespace App\Support;

use App\Enums\TaxRegime;
use App\Models\Company;

/**
 * Turns a set of priced lines into the three figures a compliant invoice has to
 * show: total hors taxes, the TVA on it, and the total toutes taxes comprises.
 *
 * Two things make this less trivial than multiplying by a rate.
 *
 * First, prices can be keyed either way. A consultancy quotes 100 000 HT and
 * the TVA goes on top; a shop quotes 100 000 on the shelf and the TVA is
 * already inside it. Extracting is not the inverse of adding — TTC / 1.1925 is
 * not TTC × 0.8075 — and using the wrong one understates or overstates the tax
 * actually owed.
 *
 * Second, rounding. Rounding each line and summing gives a different answer
 * from summing and rounding once, and only one of them reconciles against a
 * TVA declaration. Each line is rounded to the currency's minor unit first
 * because that is the figure printed next to it, and the totals are then the
 * sum of the printed figures — so the invoice adds up in the customer's hand,
 * which is the version anyone will check it against.
 *
 * XAF has no minor unit: FCFA amounts are whole francs, and there is no such
 * thing as half a franc to round to.
 */
class Vat
{
    /** Currencies with no subdivision in practical use. */
    protected const ZERO_DECIMAL = ['XAF', 'XOF'];

    /**
     * @param  array<int, array{quantity: float|string, unit_price: float|string}>  $lines
     * @return array{
     *     lines: array<int, array{net: float, tax: float, gross: float, unit_net: float}>,
     *     subtotal: float, tax_total: float, total: float, rate: float, applies: bool
     * }
     */
    public static function forCompany(Company $company, array $lines): array
    {
        return self::compute(
            $lines,
            (float) $company->vat_rate,
            (bool) $company->vat_registered,
            (bool) $company->prices_include_tax,
            (string) ($company->currency ?: 'XAF'),
        );
    }

    /**
     * @param  array<int, array{quantity: float|string, unit_price: float|string}>  $lines
     * @return array{
     *     lines: array<int, array{net: float, tax: float, gross: float, unit_net: float}>,
     *     subtotal: float, tax_total: float, total: float, rate: float, applies: bool
     * }
     */
    public static function compute(
        array $lines,
        float $rate,
        bool $registered,
        bool $pricesIncludeTax,
        string $currency = 'XAF',
    ): array {
        $applies = $registered && $rate > 0;
        $decimals = self::decimalsFor($currency);

        $computed = [];

        foreach ($lines as $line) {
            $quantity = (float) ($line['quantity'] ?? 0);
            $amount = $quantity * (float) ($line['unit_price'] ?? 0);

            if (! $applies) {
                $net = round($amount, $decimals);
                $computed[] = [
                    'net' => $net, 'tax' => 0.0, 'gross' => $net,
                    'unit_net' => self::unitNet($net, $quantity),
                ];

                continue;
            }

            if ($pricesIncludeTax) {
                // The keyed figure is the gross, so the net is extracted from it
                // and the tax is the remainder. Taking the remainder rather than
                // recomputing it guarantees net + tax == gross exactly, with no
                // stray minor unit appearing between the line and its total.
                $gross = round($amount, $decimals);
                $net = round($gross / (1 + ($rate / 100)), $decimals);
                $tax = round($gross - $net, $decimals);
            } else {
                $net = round($amount, $decimals);
                $tax = round($net * ($rate / 100), $decimals);
                $gross = round($net + $tax, $decimals);
            }

            $computed[] = [
                'net' => $net, 'tax' => $tax, 'gross' => $gross,
                'unit_net' => self::unitNet($net, $quantity),
            ];
        }

        return [
            'lines' => $computed,
            // Summed from the rounded per-line figures, which are the ones
            // printed — so the column adds up to the total beneath it.
            'subtotal' => round(array_sum(array_column($computed, 'net')), $decimals),
            'tax_total' => round(array_sum(array_column($computed, 'tax')), $decimals),
            'total' => round(array_sum(array_column($computed, 'gross')), $decimals),
            'rate' => $rate,
            'applies' => $applies,
        ];
    }

    /**
     * The net unit price to store and print, derived from the line rather than
     * the other way round.
     *
     * When prices are keyed TTC the net has to come out of the gross, and the
     * division rarely lands on a whole franc — 1 000 TTC over three units is
     * 839 net, which is not divisible by three. The line total stays
     * authoritative and the unit price is what it implies, carrying two
     * decimals so the arithmetic on the page is as close as the currency
     * allows. Printing the unit and multiplying it back is the wrong way to
     * reconcile an invoice; the line and the totals are what must agree.
     */
    protected static function unitNet(float $net, float $quantity): float
    {
        return $quantity > 0 ? round($net / $quantity, 2) : 0.0;
    }

    public static function decimalsFor(?string $currency): int
    {
        return in_array(strtoupper((string) $currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /**
     * The mention a non-registered business must carry in place of a TVA line.
     * Silence is not an option: an invoice with no tax line and no explanation
     * reads like one where the tax was forgotten.
     *
     * The regime is given as the reason, and no CGI article is quoted. A wrong
     * article number on a document a tax inspector reads is worse than none,
     * and the correct reference depends on the regime and the year's finance
     * law. If a business wants the citation on its invoices it belongs in the
     * configurable document footer, where their accountant can set it.
     */
    public static function exemptionNotice(Company $company): string
    {
        $regime = TaxRegime::tryFrom((string) $company->tax_regime);

        return $regime === null
            ? 'TVA non applicable'
            : 'TVA non applicable — '.$regime->label();
    }
}
