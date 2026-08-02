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
        'Customers' => ['view', 'create', 'update', 'delete'],
        'Products' => ['view', 'create', 'update', 'delete', 'adjust-stock'],
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
