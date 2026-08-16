<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which people hold combinations of abilities that should not sit together.
 *
 * The reasoning is already written down in app/Support/Permissions.php and
 * database/seeders/RolePermissionSeeder.php: `approve` and `execute` are kept
 * apart because "PaymentScheduler::execute() refuses an unapproved run, and
 * that refusal is the only thing between a misclick and an emptied bank
 * account". The seeder honours that in the default roles. Nothing until now
 * checked whether it was still true after a business spent a year granting
 * people one-off overrides, which is where the control actually fails — quietly,
 * one exception at a time, never in a code review.
 *
 * This class reads the live grants and says who now holds both halves.
 */
class SegregationOfDuties
{
    /**
     * The conflicts worth reporting.
     *
     * Every rule is a pair (or set) that means one person can complete a money
     * or trust cycle end to end with nobody else involved. Rules are kept few
     * and severe on purpose: a report that flags forty things is a report that
     * gets closed, and the business has to be able to act on every line.
     *
     * @var array<int, array{key: string, name: string, why: string, abilities: array<int, string>, severity: string}>
     */
    public const RULES = [
        [
            'key' => 'payables.approve-and-execute',
            'name' => 'Approves a payment run and releases it',
            'why' => 'The approval is the only check between a mistaken payment run and an empty bank account. One person holding both means there is no check at all.',
            'abilities' => ['payables.approve', 'payables.execute'],
            'severity' => 'high',
        ],
        [
            'key' => 'payables.build-and-approve',
            'name' => 'Builds the payment run and approves it',
            'why' => 'Whoever chooses which bills go on the list should not be the one who says the list is right — that is how a supplier nobody recognises gets paid.',
            'abilities' => ['payables.manage', 'payables.approve'],
            'severity' => 'high',
        ],
        [
            'key' => 'payroll.run-and-approve',
            'name' => 'Runs the payroll and approves it',
            'why' => 'A payroll is the largest payment most businesses make and the easiest to alter by one line. Running it and signing it off is a single-person path to a salary nobody agreed.',
            'abilities' => ['payroll.run', 'payroll.approve'],
            'severity' => 'high',
        ],
        [
            'key' => 'payroll.approve-and-pay',
            'name' => 'Approves the payroll and pays it',
            'why' => 'Same money, one step later: approving the month and releasing the transfers should be two signatures.',
            'abilities' => ['payroll.approve', 'payroll.pay'],
            'severity' => 'high',
        ],
        [
            'key' => 'expenses.claim-and-reimburse',
            'name' => 'Files expense claims and reimburses them',
            'why' => 'This person can pay themselves back for something they alone say they bought. It is the cheapest fraud in any business and the most common.',
            'abilities' => ['expenses.claim-create', 'expenses.claim-reimburse'],
            'severity' => 'high',
        ],
        [
            'key' => 'expenses.create-and-pay',
            'name' => 'Enters a supplier bill and pays it',
            'why' => 'One person can invent a supplier, enter what it is owed, and settle it. Nothing in the books would look wrong.',
            'abilities' => ['expenses.create', 'expenses.pay'],
            'severity' => 'high',
        ],
        [
            'key' => 'payments.record-and-refund',
            'name' => 'Takes customer money and refunds it',
            'why' => 'Cash comes in, a refund goes out to somewhere else, and the till still balances. This is what a cashier with too many permissions looks like.',
            'abilities' => ['payments.record', 'payments.refund'],
            'severity' => 'high',
        ],
        [
            'key' => 'receipts.create-and-void',
            'name' => 'Issues receipts and voids them',
            'why' => 'The receipt is the customer\'s proof that money was handed over. Whoever can destroy that proof after issuing it can pocket the difference.',
            'abilities' => ['receipts.create', 'receipts.void'],
            'severity' => 'medium',
        ],
        [
            'key' => 'procurement.rfq-manage-and-award',
            'name' => 'Runs the tender and picks the winner',
            'why' => 'Choosing who is invited to quote and choosing who wins is one person deciding where the company spends, with the paperwork of a competitive process.',
            'abilities' => ['procurement.rfq-manage', 'procurement.rfq-award'],
            'severity' => 'medium',
        ],
        [
            'key' => 'workflows.rewrite-the-control',
            'name' => 'Can rewrite the approval rules and approve payments',
            'why' => 'Permissions.php is explicit that editing a workflow is the same as being able to spend the money — a path can be written with no approver in it. Holding that alongside payment approval removes the control and uses it in one sitting.',
            'abilities' => ['workflows.manage', 'payables.approve'],
            'severity' => 'high',
        ],
        [
            'key' => 'users.grant-and-spend',
            'name' => 'Can change who holds which role, and release money',
            'why' => 'This combination is self-service: the ability to grant abilities plus the ability to move money means every other separation in this list can be undone by the person it constrains.',
            'abilities' => ['users.update-role', 'payables.execute'],
            'severity' => 'high',
        ],
        [
            'key' => 'banking.pay-and-reconcile',
            'name' => 'Releases money and reconciles the bank',
            'why' => 'Reconciliation is the second pair of eyes that notices a payment nobody expected. The person who made the payment cannot be that pair of eyes.',
            'abilities' => ['payables.execute', 'banking.reconcile'],
            'severity' => 'medium',
        ],
        [
            'key' => 'products.adjust-and-void',
            'name' => 'Adjusts stock counts and voids sales',
            'why' => 'Goods walk out, the sale is voided and the count is corrected to match. Each act alone is routine; together they leave no trace in the stock figures.',
            'abilities' => ['products.adjust-stock', 'sales.void'],
            'severity' => 'medium',
        ],
    ];

    /**
     * Real findings — everyone except the people who cannot avoid it.
     *
     * @return Collection<int, array{user: User, role: ?Role, rule: array}>
     */
    public static function findings(Company $company): Collection
    {
        return static::scan($company)->reject(fn (array $f) => static::isUnavoidable($f['role']))->values();
    }

    /**
     * The same conflicts, for the people whose job is to hold everything.
     *
     * Reported apart rather than suppressed. An Owner running the books alone
     * genuinely has no separation of duties, and that is a fact worth a
     * business owner seeing once — but repeating it on every rule would bury
     * the findings that can actually be fixed.
     *
     * @return Collection<int, array{user: User, role: ?Role, rule: array}>
     */
    public static function unavoidable(Company $company): Collection
    {
        return static::scan($company)->filter(fn (array $f) => static::isUnavoidable($f['role']))->values();
    }

    /**
     * Every ability every active member holds, for the permission matrix.
     *
     * @return Collection<int, array{user: User, role: ?Role, abilities: array<int, string>}>
     */
    public static function holdings(Company $company): Collection
    {
        return static::members($company)->map(fn (User $user) => [
            'user' => $user,
            'role' => $user->roleIn($company),
            'abilities' => static::abilitiesOf($user, $company),
        ]);
    }

    /** @return Collection<int, array{user: User, role: ?Role, rule: array}> */
    protected static function scan(Company $company): Collection
    {
        $findings = collect();

        foreach (static::members($company) as $user) {
            $held = static::abilitiesOf($user, $company);

            foreach (self::RULES as $rule) {
                if (array_diff($rule['abilities'], $held) === []) {
                    $findings->push(['user' => $user, 'role' => $user->roleIn($company), 'rule' => $rule]);
                }
            }
        }

        return $findings;
    }

    /**
     * Asked through hasPermissionIn, not read off the role.
     *
     * The whole point of this report is the one-off override, and an override
     * only exists in company_user_permission — a role-based reading would miss
     * every grant a business actually made by hand, and would also miss the
     * explicit revoke that cleared a conflict somebody already noticed.
     *
     * @return array<int, string>
     */
    protected static function abilitiesOf(User $user, Company $company): array
    {
        $needed = collect(self::RULES)->flatMap(fn (array $r) => $r['abilities'])->unique();

        return $needed->filter(fn (string $ability) => $user->hasPermissionIn($company, $ability))->values()->all();
    }

    /**
     * The Owner and the Administrator hold everything by design.
     *
     * hasPermissionIn short-circuits true for the Owner so they can never lock
     * themselves out of their own business; the seeder grants the Administrator
     * '*'. Both would therefore trip every rule in the list.
     */
    protected static function isUnavoidable(?Role $role): bool
    {
        return in_array($role?->slug, [Role::OWNER, Role::ADMINISTRATOR], true);
    }

    /** @return Collection<int, User> */
    protected static function members(Company $company): Collection
    {
        return $company->users()
            ->wherePivot('status', 'active')
            ->orderBy('users.name')
            ->get();
    }
}
