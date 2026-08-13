<?php

namespace App\Support;

/**
 * What a token is allowed to do, on top of what its user is allowed to do.
 *
 * These are a *ceiling*, never a grant. A token carrying `write` still cannot
 * issue an invoice if its user lacks `sales.issue`; the permission catalogue
 * and the module switch are checked exactly as before, and this narrows the
 * result. The two are ANDed on purpose — a token that could widen its user's
 * access would be a way to hand out permissions the business never granted.
 *
 * Why it exists: an integration usually needs far less than the person who set
 * it up. A dashboard that reads sales figures should not be able to void an
 * invoice because the owner happened to be the one who created its token, and
 * a leaked read-only key should not be able to move money.
 *
 * `*` is the full set, which is what a token gets when the caller asks for no
 * abilities in particular — a plain "log me in from my own script" token.
 */
class TokenAbilities
{
    /** Read anything the user can read. */
    public const READ = 'read';

    /** Create and update records the user could create and update. */
    public const WRITE = 'write';

    /**
     * Move money: record a payment, settle or void an expense.
     *
     * Separate from `write` because "can add a customer" and "can take a
     * payment" are not the same trust, and most integrations want the first
     * without the second.
     */
    public const MONEY = 'money';

    /** Read the staff file and payroll figures. */
    public const PEOPLE = 'people';

    /**
     * @var array<string, string> ability => what a user should understand it to mean
     */
    public const CATALOGUE = [
        self::READ => 'Read your business data',
        self::WRITE => 'Create and change records',
        self::MONEY => 'Record payments and expenses',
        self::PEOPLE => 'Read staff and payroll',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::CATALOGUE);
    }

    /**
     * Normalise what a caller asked for.
     *
     * An empty request means "everything this user can do", which keeps the
     * simple case simple: a script written by the business owner for the
     * business owner should not have to enumerate scopes to work.
     *
     * @param  array<int, string>|null  $requested
     * @return array<int, string>
     */
    public static function normalise(?array $requested): array
    {
        if ($requested === null || $requested === []) {
            return ['*'];
        }

        $valid = array_values(array_intersect($requested, self::all()));

        // Asking only for abilities that do not exist is a mistake worth
        // failing on rather than quietly upgrading to `*`.
        return $valid === [] ? [] : $valid;
    }
}
