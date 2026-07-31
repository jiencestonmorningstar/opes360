<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivity;
use App\Models\User;
use App\Notifications\AdminResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
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

    public function test_a_signed_in_admin_visiting_the_login_page_lands_on_the_dashboard(): void
    {
        // Regression: the framework's default guest-redirect target is
        // whatever route is named "dashboard" — the *business* one — with
        // no guard awareness, so this used to bounce a signed-in admin to
        // the public marketing homepage instead of back to /admin.
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/login')
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_signed_in_business_user_visiting_business_login_still_lands_on_their_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/login')
            ->assertRedirect(route('dashboard'));
    }

    public function test_demo_admin_login_button_signs_in_with_the_seeded_credential(): void
    {
        config(['opes.demo.enabled' => true]);

        $this->get('/admin/login')->assertSee('Demo platform admin');

        $this->post('/admin/login', [
            'email' => config('opes.demo.admin_email'),
            'password' => config('opes.demo.password'),
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->admin, 'admin');
    }

    public function test_demo_admin_login_is_hidden_when_demo_logins_are_disabled(): void
    {
        config(['opes.demo.enabled' => false]);

        $this->get('/admin/login')->assertDontSee('Demo platform admin');
    }

    public function test_business_logout_still_works_while_the_company_is_suspended(): void
    {
        $owner = User::factory()->create();
        $company = $this->makeCompany(['owner_id' => $owner->id]);
        $this->joinCompany($company, $owner);
        $owner->forceFill(['current_company_id' => $company->id])->save();
        $company->forceFill(['suspended_at' => now()])->save();

        $this->actingAs($owner)
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_login_is_throttled_across_different_emails_from_the_same_ip(): void
    {
        // The per-(email+IP) key alone would let each of these guessed emails
        // take five failed attempts of its own before ever being throttled;
        // the IP-wide key (30/15min) must catch the pattern once the total
        // crosses its own threshold, well before any single email does.
        for ($i = 0; $i < 30; $i++) {
            $this->post('/admin/login', [
                'email' => "guess{$i}@example.com",
                'password' => 'wrong',
            ]);
        }

        $this->post('/admin/login', [
            'email' => 'admin@opes360.com',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_admin_can_view_a_soft_deleted_company_but_cannot_act_on_it(): void
    {
        $company = $this->makeCompany();
        $company->delete();

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/companies/'.$company->slug)
            ->assertOk()
            ->assertSee('deleted');

        // Deliberately not withTrashed() on the route: acting on a deleted
        // company isn't a meaningful operation, so these still 404.
        $this->actingAs($this->admin, 'admin')
            ->post('/admin/companies/'.$company->slug.'/suspend')
            ->assertNotFound();
    }

    public function test_soft_deleted_companies_are_excluded_from_the_default_list_but_shown_under_the_deleted_filter(): void
    {
        $visible = $this->makeCompany(['name' => 'Still Here Ltd']);
        $deleted = $this->makeCompany(['name' => 'Gone Ltd']);
        $deleted->delete();

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/companies')
            ->assertSee('Still Here Ltd')
            ->assertDontSee('Gone Ltd');

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/companies?status=deleted')
            ->assertSee('Gone Ltd')
            ->assertDontSee('Still Here Ltd');
    }

    public function test_admin_activity_records_the_acting_ip_address(): void
    {
        $company = $this->makeCompany();

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/companies/'.$company->slug.'/suspend', [], ['REMOTE_ADDR' => '203.0.113.7']);

        $this->assertDatabaseHas('platform_admin_activity', [
            'action' => 'suspended_company',
            'subject_id' => $company->id,
            'ip_address' => '203.0.113.7',
        ]);
    }

    public function test_admin_password_reset_link_is_emailed_and_works(): void
    {
        Notification::fake();

        $this->post('/admin/forgot-password', ['email' => 'admin@opes360.com'])
            ->assertSessionHas('status');

        Notification::assertSentTo($this->admin, AdminResetPassword::class, function (AdminResetPassword $notification) {
            $this->post('/admin/reset-password', [
                'token' => $notification->token,
                'email' => 'admin@opes360.com',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertRedirect(route('admin.login'));

            return true;
        });

        RateLimiter::clear('admin-login|admin@opes360.com|127.0.0.1');
        RateLimiter::clear('admin-login-ip|127.0.0.1');

        $this->post('/admin/login', [
            'email' => 'admin@opes360.com',
            'password' => 'a-brand-new-password',
        ])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->admin, 'admin');
    }

    public function test_admin_password_reset_does_not_reveal_whether_an_address_exists(): void
    {
        Notification::fake();

        $this->post('/admin/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status', 'If that address has an admin account, a reset link is on its way.');

        Notification::assertNothingSent();
    }
}
