<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RolePermissionSeeder extends Seeder
{
    /** group => [action, ...] — shared with the gate definitions, never forked. */
    protected array $permissions = Permissions::CATALOGUE;

    /**
     * The seven roles from Module 15. `*` grants everything in a group; an empty
     * array grants nothing.
     */
    protected array $roles = [
        'owner' => ['name' => 'Owner', 'level' => 1, 'grants' => '*'],
        'administrator' => ['name' => 'Administrator', 'level' => 2, 'grants' => '*'],
        'manager' => ['name' => 'Manager', 'level' => 3, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view', 'create', 'update', 'issue', 'approve'],
            'Receipts' => ['view', 'create'],
            'Payments' => ['view', 'record'],
            'Customers' => ['view', 'create', 'update'],
            'Products' => ['view', 'create', 'update', 'adjust-stock'],
            'Papers' => ['view', 'create', 'issue'],
            'Forms' => ['view', 'create', 'update', 'delete', 'responses'],
            'Events' => ['view', 'create', 'update', 'void', 'check-in'],
            'Loyalty' => ['view', 'manage', 'redeem'],
            'Reports' => ['view', 'export'],
            'Accounting' => ['view', 'export', 'manage'],
            'Users' => ['view'],
            'Devices' => ['view'],
            'Settings' => ['view'],
        ]],
        'accountant' => ['name' => 'Accountant', 'level' => 4, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view', 'create', 'update', 'issue'],
            'Receipts' => ['view', 'create'],
            'Payments' => ['view', 'record', 'refund'],
            'Customers' => ['view', 'create', 'update'],
            'Products' => ['view'],
            'Papers' => ['view', 'create'],
            'Forms' => ['view', 'responses'],
            'Events' => ['view'],
            'Loyalty' => ['view'],
            'Reports' => ['view', 'export'],
            // The books are the accountant's job before anyone else's.
            'Accounting' => ['view', 'export', 'manage'],
            'Settings' => ['view'],
        ]],
        'sales-officer' => ['name' => 'Sales Officer', 'level' => 5, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view', 'create', 'update'],
            'Receipts' => ['view', 'create'],
            'Payments' => ['view', 'record'],
            'Customers' => ['view', 'create', 'update'],
            'Products' => ['view'],
            'Papers' => ['view', 'create'],
            'Forms' => ['view', 'create', 'update', 'responses'],
            'Events' => ['view', 'create', 'update', 'check-in'],
            'Loyalty' => ['view', 'redeem'],
            'Reports' => ['view'],
        ]],
        // No Papers for a Cashier: a till operator has no reason to read the
        // business's employment letters and contracts. Read Only does get them,
        // because that role is for auditors and accountants looking in.
        // Events check-in for a Cashier: door staff scanning tickets at a paid
        // event is the same job as the till, just standing up.
        'cashier' => ['name' => 'Cashier', 'level' => 6, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view'],
            'Receipts' => ['view', 'create'],
            'Payments' => ['view', 'record'],
            'Customers' => ['view', 'create'],
            'Products' => ['view'],
            'Events' => ['view', 'check-in'],
            'Loyalty' => ['view', 'redeem'],
        ]],
        'read-only' => ['name' => 'Read Only', 'level' => 7, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view'],
            'Receipts' => ['view'],
            'Payments' => ['view'],
            'Customers' => ['view'],
            'Products' => ['view'],
            'Papers' => ['view'],
            'Forms' => ['view', 'responses'],
            'Events' => ['view'],
            'Loyalty' => ['view'],
            'Reports' => ['view'],
        ]],
    ];

    public function run(): void
    {
        $ids = [];

        foreach ($this->permissions as $group => $actions) {
            foreach ($actions as $action) {
                $slug = Permissions::slug($group, $action);

                $permission = Permission::updateOrCreate(
                    ['slug' => $slug],
                    ['name' => Str::headline($action).' '.$group, 'group' => $group],
                );

                $ids[$group][$action] = $permission->id;
            }
        }

        foreach ($this->roles as $slug => $definition) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $definition['name'],
                    'level' => $definition['level'],
                    'is_system' => true,
                ],
            );

            $grants = $definition['grants'] === '*'
                ? collect($ids)->flatten()->all()
                : collect($definition['grants'])
                    ->flatMap(fn (array $actions, string $group) => collect($actions)
                        ->map(fn (string $action) => $ids[$group][$action] ?? null))
                    ->filter()
                    ->all();

            $role->permissions()->sync($grants);
        }
    }
}
