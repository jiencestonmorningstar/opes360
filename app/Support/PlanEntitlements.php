<?php

namespace App\Support;

use App\Models\Company;

/**
 * Which modules a company's plan includes — the pricing page's comparison
 * table, made authoritative instead of decorative. Only the four modules
 * that page actually gates by tier are enforced here (Papers from Growth,
 * Events and Loyalty from Business); everything else (Sales, Customers,
 * Products, Payments, Reports) is Basic-included and never plan-checked.
 *
 * A demo or trial account is deliberately exempt: the whole point of a trial
 * is to show what a higher tier includes, so gating it here would undercut
 * the sales pitch it exists to make. Enforcement starts once an account is
 * `active` on a specific plan.
 */
class PlanEntitlements
{
    public const PLANS = ['basic', 'growth', 'business'];

    /** Lower index = included from an earlier plan. */
    protected const MODULE_MIN_PLAN = [
        'papers' => 'growth',
        'forms' => 'growth',
        'events' => 'business',
        'loyalty' => 'business',
    ];

    public static function allows(Company $company, string $module): bool
    {
        if (! array_key_exists($module, self::MODULE_MIN_PLAN)) {
            return true;
        }

        if ($company->account_type !== 'active') {
            return true;
        }

        return self::rank($company->plan) >= self::rank(self::MODULE_MIN_PLAN[$module]);
    }

    /**
     * Whether a permission ability (e.g. "forms.create") is allowed by the
     * company's plan. Abilities outside the four gated groups are always
     * allowed here — this only answers the plan question, never the role one.
     */
    public static function allowsAbility(Company $company, string $ability): bool
    {
        $module = explode('.', $ability, 2)[0] ?? '';

        return self::allows($company, $module);
    }

    /** The lowest plan that includes a module, for an upgrade prompt. */
    public static function minimumPlanFor(string $module): ?string
    {
        return self::MODULE_MIN_PLAN[$module] ?? null;
    }

    protected static function rank(string $plan): int
    {
        $index = array_search($plan, self::PLANS, true);

        return $index === false ? 0 : $index;
    }
}
