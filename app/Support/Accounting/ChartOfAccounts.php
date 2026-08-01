<?php

namespace App\Support\Accounting;

use App\Models\Company;
use App\Models\LedgerAccount;

/**
 * A starter SYSCOHADA chart, and the roles the posting engine looks accounts
 * up by.
 *
 * ── Read this before relying on the numbers ──────────────────────────────
 *
 * These are the standard accounts a small OHADA business uses, at the
 * three-digit level. They are NOT reproduced from the official plan comptable
 * — that document could not be retrieved when this was written — and no
 * four-digit subdivisions are invented here, because guessing a sub-account
 * and printing it on a balance an accountant files is worse than not offering
 * one.
 *
 * Treat this as a starting point that an accountant confirms and extends, not
 * as an authority. The chart is per company and editable for exactly that
 * reason, and `verified_at` on the company records whether anyone has actually
 * checked it. Nothing here should reach a tax filing unreviewed.
 *
 * ── Why roles ───────────────────────────────────────────────────────────
 *
 * The posting engine asks for `receivables`, not for "411". A business that
 * subdivides its receivables into 4111 and 4112 can point the role at either
 * without the engine knowing, and a country whose numbering differs slightly
 * only has to remap the roles.
 */
class ChartOfAccounts
{
    /**
     * role => [number, name]
     *
     * The minimum set needed to post a sale and its settlement. Everything
     * else an accountant adds themselves.
     */
    public const ROLES = [
        'receivables' => ['411', 'Clients'],
        'payables' => ['401', 'Fournisseurs'],
        'vat_collected' => ['443', 'État, TVA facturée'],
        'vat_deductible' => ['445', 'État, TVA récupérable'],
        'bank' => ['521', 'Banques'],
        'cash' => ['571', 'Caisse'],
        'purchases' => ['601', 'Achats de marchandises'],
        'sales_goods' => ['701', 'Ventes de marchandises'],
        'sales_services' => ['706', 'Services vendus'],
    ];

    /** The eight SYSCOHADA classes, for grouping a balance or a grand livre. */
    public const CLASSES = [
        1 => 'Ressources durables',
        2 => 'Actif immobilisé',
        3 => 'Stocks',
        4 => 'Tiers',
        5 => 'Trésorerie',
        6 => 'Charges des activités ordinaires',
        7 => 'Produits des activités ordinaires',
        8 => 'Autres charges et autres produits',
    ];

    /**
     * Writes the starter chart for a company, skipping any account it already
     * has — so running it again after an accountant has edited the chart adds
     * what is missing without overwriting their work.
     *
     * @return int how many accounts were created
     */
    public static function seed(Company $company): int
    {
        $existing = LedgerAccount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->pluck('number')
            ->all();

        $created = 0;

        foreach (self::ROLES as [$number, $name]) {
            if (in_array($number, $existing, true)) {
                continue;
            }

            $class = LedgerAccount::classOf($number);

            LedgerAccount::create([
                'company_id' => $company->id,
                'number' => $number,
                'name' => $name,
                'class' => $class,
                'normal_balance' => self::normalBalanceFor($number, $class),
            ]);

            $created++;
        }

        return $created;
    }

    /**
     * Class 4 holds both what customers owe the business and what the business
     * owes others, so its side cannot come from the class alone. Receivables
     * are debit-normal; payables and tax collected are credit-normal. TVA
     * récupérable is tax the business has paid and can reclaim — an asset, and
     * so debit-normal despite sitting in class 4 beside its opposite.
     */
    public static function normalBalanceFor(string $number, int $class): string
    {
        if ($class === 4) {
            return in_array($number, ['411', '445'], true) ? 'debit' : 'credit';
        }

        return LedgerAccount::normalBalanceFor($class);
    }

    /** The account a role points at, or null when the chart has not been seeded. */
    public static function account(Company $company, string $role): ?LedgerAccount
    {
        $number = self::ROLES[$role][0] ?? null;

        if ($number === null) {
            return null;
        }

        return LedgerAccount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('number', $number)
            ->first();
    }
}
