<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The production first-run path.
 *
 * `migrate` alone leaves the roles table empty, and registration assigns the
 * owner role by slug — so without this command every business created on a
 * fresh install would get a null role. That is the failure these tests exist
 * to prevent from coming back.
 */
class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $strongPassword = 'Tr7#vQx2Lm!Pw9z';

    public function test_it_seeds_the_reference_data_registration_depends_on(): void
    {
        Role::query()->delete();
        $this->assertSame(0, Role::count());

        $this->artisan('opes:install --skip-admin')->assertSuccessful();

        $this->assertTrue(
            Role::where('slug', Role::OWNER)->exists(),
            'Registration looks the owner role up by slug; without it new companies get a null role.',
        );
    }

    public function test_it_creates_a_platform_administrator(): void
    {
        $this->artisan('opes:install', [
            '--admin-email' => 'ops@example.com',
            '--admin-name' => 'Ops',
            '--admin-password' => $this->strongPassword,
        ])->assertSuccessful();

        $admin = PlatformAdmin::where('email', 'ops@example.com')->first();

        $this->assertNotNull($admin);
        $this->assertSame(PlatformAdmin::ROLE_ADMIN, $admin->role);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check($this->strongPassword, $admin->password));
    }

    public function test_it_refuses_a_weak_administrator_password(): void
    {
        $this->artisan('opes:install', [
            '--admin-email' => 'ops@example.com',
            '--admin-name' => 'Ops',
            '--admin-password' => 'password',
        ])->assertFailed();

        $this->assertDatabaseMissing('platform_admins', ['email' => 'ops@example.com']);
    }

    public function test_it_never_creates_the_demo_admin_or_demo_company(): void
    {
        $this->artisan('opes:install --skip-admin')->assertSuccessful();

        // The default seeder would create both, one of them with the password
        // "password" — which is exactly why production must not run it.
        $this->assertDatabaseMissing('platform_admins', ['email' => config('opes.demo.admin_email')]);
        $this->assertSame(0, Company::withoutGlobalScopes()->count());
    }

    public function test_running_it_twice_is_safe(): void
    {
        $this->artisan('opes:install --skip-admin')->assertSuccessful();
        $before = Role::count();

        $this->artisan('opes:install --skip-admin')->assertSuccessful();

        $this->assertSame($before, Role::count());
    }
}
