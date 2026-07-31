<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Module — Platform Admin.
 *
 * A second, fully separate authentication system from the tenant `web`
 * guard: platform admins are not company members and hold no business
 * permissions, so these tests never touch CurrentCompany or role gates.
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformAdmin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = PlatformAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'admin@opes360.com',
            'password' => Hash::make('password'),
        ]);
    }

    protected function makeCompany(array $overrides = []): Company
    {
        $owner = User::factory()->create();

        return Company::create(array_merge([
            'slug' => 'acme-'.uniqid(),
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ], $overrides));
    }

    public function test_guests_cannot_reach_the_admin_dashboard(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_a_business_user_session_does_not_grant_admin_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_platform_admin_can_log_in_and_see_the_dashboard(): void
    {
        $this->post('/admin/login', [
            'email' => 'admin@opes360.com',
            'password' => 'password',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->admin, 'admin');

        $this->get('/admin')->assertOk()->assertSee('Companies');
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->post('/admin/login', [
            'email' => 'admin@opes360.com',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors();

        $this->assertGuest('admin');
    }

    public function test_admin_can_see_every_company_regardless_of_tenant_scope(): void
    {
        $a = $this->makeCompany(['name' => 'Acme Ltd']);
        $b = $this->makeCompany(['name' => 'Beta Ltd']);

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/companies')
            ->assertOk()
            ->assertSee('Acme Ltd')
            ->assertSee('Beta Ltd');

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/companies/'.$a->slug)
            ->assertOk()
            ->assertSee('Acme Ltd');

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/companies/'.$b->slug)
            ->assertOk()
            ->assertSee('Beta Ltd');
    }

    public function test_admin_can_suspend_and_reactivate_a_company(): void
    {
        $company = $this->makeCompany();

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/companies/'.$company->slug.'/suspend')
            ->assertRedirect();

        $this->assertTrue($company->fresh()->isSuspended());
        $this->assertDatabaseHas('platform_admin_activity', [
            'platform_admin_id' => $this->admin->id,
            'action' => 'suspended_company',
            'subject_id' => $company->id,
        ]);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/companies/'.$company->slug.'/activate')
            ->assertRedirect();

        $this->assertFalse($company->fresh()->isSuspended());
    }

    public function test_admin_can_change_a_companys_plan_and_it_is_logged(): void
    {
        $company = $this->makeCompany(['plan' => 'basic']);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/companies/'.$company->slug.'/plan', ['plan' => 'business'])
            ->assertRedirect();

        $this->assertSame('business', $company->fresh()->plan);
        $this->assertDatabaseHas('platform_admin_activity', [
            'platform_admin_id' => $this->admin->id,
            'action' => 'changed_plan',
            'subject_id' => $company->id,
        ]);
    }

    public function test_a_suspended_company_locks_out_its_own_users(): void
    {
        $owner = User::factory()->create();
        $company = $this->makeCompany(['owner_id' => $owner->id]);
        $this->joinCompany($company, $owner);
        $owner->forceFill(['current_company_id' => $company->id])->save();

        $company->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($owner)->get('/')->assertRedirect(route('account-suspended'));
    }

    public function test_admin_can_log_out(): void
    {
        $this->actingAs($this->admin, 'admin');

        $this->post('/admin/logout')->assertRedirect();

        $this->assertGuest('admin');
    }
}
