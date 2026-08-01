<?php

namespace App\Support\Accounting;

use App\Models\Company;
use App\Models\LedgerAccount;

/**
 * A starter SYSCOHADA chart, and the roles the posting engine looks accounts
 * up by.
 *
 * ── Provenance of the numbers ────────────────────────────────────────────
 *
 * Verified two ways. Every number and label below was checked against the
 * machine-readable SYSCOHADA chart shipped in Odoo's l10n_syscohada
 * localization (l10n_syscohada_data.xml, fetched from GitHub — the full
 * OHADA plan as a production accounting system encodes it), and the numbers
 * were independently corroborated against published references to the révisé
 * plan (the 2017 revision, in force since 1 January 2018 under the AUDCIF).
 * Every account used here agrees across both, i.e. these accounts are
 * unchanged by the revision. The official OHADA PDF itself remains
 * unreachable from this network, so "verified" here means against those two
 * independent encodings, not against the source document.
 *
 * The labels are the plan's own, which is why some are more specific than a
 * UI would choose: 521 is "Banques locales" because 522 is banks in other
 * OHADA states, and 571 is "Caisse siège social" because 572 is a branch
 * till. Renaming them to something friendlier would misdescribe accounts
 * whose meaning is positional.
 *
 * The chart works at the three-digit compte principal level. The plan
 * subdivides further — full practice posts at the four-digit compte
 * divisionnaire — and SUB_ACCOUNTS records those subdivisions verbatim for an
 * accountant extending the chart. They are deliberately not seeded: which of
 * them a business needs is a decision about the business, not a default.
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
        'payables' => ['401', 'Fournisseurs, dettes en compte'],
        'vat_collected' => ['443', 'État, TVA facturée'],
        'vat_deductible' => ['445', 'État, TVA récupérable'],
        // Where TVA facturée less TVA récupérable nets out for the monthly
        // declaration. Seeded so the account exists when an accountant comes
        // to post the declaration; nothing posts to it automatically, because
        // when and how to net the two is their call, not the software's.
        'vat_due' => ['444', 'État, TVA due ou crédit de TVA'],
        'bank' => ['521', 'Banques locales'],
        'cash' => ['571', 'Caisse siège social'],
        'purchases' => ['601', 'Achats de marchandises'],
        'sales_goods' => ['701', 'Ventes de marchandises'],
        'sales_services' => ['706', 'Services vendus'],
    ];

    /**
     * The four-digit subdivisions the published references list for the two
     * TVA accounts and the two trading accounts. Recorded for an accountant
     * extending the chart; not seeded, because which of these a business needs
     * depends on what it sells and where.
     *
     * @var array<string, array<string, string>>
     */
    public const SUB_ACCOUNTS = [
        '443' => [
            '4431' => 'TVA facturée sur ventes',
            '4432' => 'TVA facturée sur prestations de services',
            '4433' => 'TVA facturée sur travaux',
        ],
        '444' => [
            '4441' => 'État, TVA due',
            '4449' => 'État, crédit de TVA à reporter',
        ],
        '445' => [
            '4451' => 'TVA récupérable sur immobilisations',
            '4452' => 'TVA récupérable sur achats',
            '4453' => 'TVA récupérable sur transport',
            '4454' => 'TVA récupérable sur services extérieurs et autres charges',
        ],
        '601' => [
            '6011' => 'Achats de marchandises dans la Région',
            '6012' => 'Achats de marchandises hors Région',
        ],
        '701' => [
            '7011' => 'Ventes de marchandises dans la Région',
            '7012' => 'Ventes de marchandises hors Région',
        ],
        '706' => [
            '7061' => 'Services vendus dans la Région',
            '7062' => 'Services vendus hors Région',
        ],
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
            // 411 is owed to the business and 445 is tax it can reclaim —
            // both assets, and so are their subdivisions (4111, 4452…), which
            // is why this matches on prefix. 4191 Clients avances reçues
            // deliberately does not match: it starts 419, and money a
            // customer paid in advance is owed *by* the business.
            return str_starts_with($number, '411') || str_starts_with($number, '445')
                ? 'debit'
                : 'credit';
        }

        return LedgerAccount::normalBalanceFor($class);
    }

    /**
     * The side a new account most likely wants, offered as a default the
     * accountant can override. An account under an existing one inherits its
     * parent's side — the longest matching prefix wins, so 4111 follows 411 —
     * and anything without a parent falls to the class rule.
     */
    public static function suggestSide(Company $company, string $number): string
    {
        $parent = LedgerAccount::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('number', '!=', $number)
            ->get(['number', 'normal_balance'])
            ->filter(fn (LedgerAccount $a) => str_starts_with($number, $a->number))
            ->sortByDesc(fn (LedgerAccount $a) => strlen($a->number))
            ->first();

        if ($parent !== null) {
            return $parent->normal_balance;
        }

        return self::normalBalanceFor($number, LedgerAccount::classOf($number));
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
