<?php

namespace Tests\Feature\Audit;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared setup for the audit screens.
 *
 * Every test here needs two things that are easy to forget: a seeded role table
 * (the gates read permissions through it, so without the seeder an Owner is
 * denied everything) and a *second* company with its own log rows, because the
 * one bug that would matter in this feature is an audit screen showing another
 * business its neighbour's history.
 */
abstract class AuditTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Company $otherCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create(['name' => 'Awa Ndiaye']);
        $this->company = $this->makeCompany('Acme Sarl', $this->user);
        $this->joinCompany($this->company, $this->user, Role::OWNER);

        $stranger = User::factory()->create(['name' => 'Someone Else']);
        $this->otherCompany = $this->makeCompany('Rival Sarl', $stranger);
        $this->joinCompany($this->otherCompany, $stranger, Role::OWNER);

        app(CurrentCompany::class)->set($this->company);
        $this->actingAs($this->user);
    }

    protected function makeCompany(string $name, User $owner): Company
    {
        return Company::create([
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'name' => $name,
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
    }

    /** Give a user the named role in the current company, replacing whatever they had. */
    protected function asRole(User $user, string $role): User
    {
        $this->joinCompany($this->company, $user, $role);
        $user->forgetRoleCache();

        return $user;
    }
}
