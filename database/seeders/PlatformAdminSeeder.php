<?php

namespace Database\Seeders;

use App\Models\PlatformAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * One demo platform admin, the same way john@opesware.com is a demo business
 * owner — a working login to show the panel with, not a production account.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        PlatformAdmin::updateOrCreate(
            ['email' => config('opes.demo.admin_email')],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make(config('opes.demo.password')),
                'email_verified_at' => now(),
            ],
        );
    }
}
