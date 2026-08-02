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
        $this->call(PlatformAdminSeeder::class);

        $this->call(DemoCompanySeeder::class);

        // A worked example of the partner programme. Without it the feature is
        // unreachable from a demo login, since a plain business account is
        // denied every partner ability by design.
        $this->call(DemoSecretariatSeeder::class);
    }
}
