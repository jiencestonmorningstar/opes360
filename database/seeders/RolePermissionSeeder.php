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
            'Reports' => ['view', 'export'],
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
            'Reports' => ['view', 'export'],
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
            'Reports' => ['view'],
        ]],
        // No Papers for a Cashier: a till operator has no reason to read the
        // business's employment letters and contracts. Read Only does get them,
        // because that role is for auditors and accountants looking in.
        'cashier' => ['name' => 'Cashier', 'level' => 6, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view'],
            'Receipts' => ['view', 'create'],
            'Payments' => ['view', 'record'],
            'Customers' => ['view', 'create'],
            'Products' => ['view'],
        ]],
        'read-only' => ['name' => 'Read Only', 'level' => 7, 'grants' => [
            'Business' => ['view'],
            'Sales' => ['view'],
            'Receipts' => ['view'],
            'Payments' => ['view'],
            'Customers' => ['view'],
            'Products' => ['view'],
            'Papers' => ['view'],
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
