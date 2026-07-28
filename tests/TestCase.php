<?php

namespace Tests;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Give a user a real membership in a company at the named role.
     *
     * Authorization reads permissions through the `company_user` pivot, so a
     * user who merely owns the row but has no membership is denied everything —
     * exactly as in production, where SetCurrentCompany re-checks the pivot on
     * every request. Tests that exercise a gated action must go through here.
     */
    protected function joinCompany(Company $company, User $user, string $role = Role::OWNER): void
    {
        if (Role::query()->count() === 0) {
            $this->seed(RolePermissionSeeder::class);
        }

        $company->users()->syncWithoutDetaching([
            $user->id => [
                'role_id' => Role::where('slug', $role)->value('id'),
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);
    }
}
