<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The canonical permission catalogue (Module 15).
 *
 * The seeder writes these rows and the auth layer defines a gate for every one
 * of them. They must come from the same place: an ability that exists as a
 * seeded permission but not as a gate is silently *denied* to everyone except
 * the Owner, which is the worst kind of failure — it looks like it works.
 */
class Permissions
{
    /** group => [action, ...] */
    public const CATALOGUE = [
        'Business' => ['view', 'update', 'manage-branding', 'manage-stationery'],
        'Sales' => ['view', 'create', 'update', 'issue', 'void', 'approve'],
        'Receipts' => ['view', 'create', 'void'],
        'Payments' => ['view', 'record', 'refund'],
        // Money going out. Separate from Payments, which is money coming in:
        // a cashier who may take a customer's money has no business recording
        // what the company spends.
        'Expenses' => ['view', 'create', 'update', 'pay', 'void'],
        /*
         * People and pay. Three groups rather than one, because they are three
         * different jobs: a manager keeps the staff file, an accountant runs
         * the payroll, and only an owner should be able to approve a month and
         * post it to the books. Salaries are also the one thing in this system
         * that everybody is curious about and almost nobody should see.
         */
        'Employees' => ['view', 'create', 'update', 'delete'],
        'Payroll' => ['view', 'run', 'approve', 'pay', 'void'],
        'Leave' => ['view', 'request', 'approve'],
        'Customers' => ['view', 'create', 'update', 'delete'],
        // `manage-locations` sits in this group because it is about stock, but
        // belongs to its own module: a business can sell things from one shelf
        // without ever needing a warehouse. See config/modules.php.
        'Products' => ['view', 'create', 'update', 'delete', 'adjust-stock', 'manage-locations'],
        // What the business owns and what it banks with. Both are the
        // accountant's ground rather than the shopkeeper's, which is why they
        // are separate groups instead of actions on Accounting.
        'Assets' => ['view', 'create', 'update', 'depreciate', 'dispose'],
        'Banking' => ['view', 'manage', 'import', 'reconcile'],
        'Papers' => ['view', 'create', 'issue', 'void'],
        'Forms' => ['view', 'create', 'update', 'delete', 'responses'],
        'Events' => ['view', 'create', 'update', 'void', 'check-in'],
        'Loyalty' => ['view', 'manage', 'redeem'],
        'Reports' => ['view', 'export'],
        'Accounting' => ['view', 'export', 'manage'],
        // The secretariat programme. Not plan-gated — it is how a partner pays
        // the platform, so putting it behind a tier would be self-defeating —
        // but it is only reachable at all on a secretariat account. See
        // AuthServiceProvider, which adds that second condition to these gates.
        'Partners' => ['view', 'manage', 'issue', 'withdraw'],
        'Users' => ['view', 'invite', 'update-role', 'remove'],
        'Devices' => ['view', 'revoke'],
        'Settings' => ['view', 'update'],
    ];

    /**
     * Every permission slug, flat: `sales.issue`, `products.adjust-stock`, …
     *
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        $slugs = [];

        foreach (self::CATALOGUE as $group => $actions) {
            foreach ($actions as $action) {
                $slugs[] = self::slug($group, $action);
            }
        }

        return $slugs;
    }

    public static function slug(string $group, string $action): string
    {
        return Str::slug($group).'.'.$action;
    }
}
