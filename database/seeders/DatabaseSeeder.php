<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Roles and permissions are reference data and must exist before any
        // company is created, since membership requires a role.
        $this->call(RolePermissionSeeder::class);

        $this->call(DemoCompanySeeder::class);
    }
}
