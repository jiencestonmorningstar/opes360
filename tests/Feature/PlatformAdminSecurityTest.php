<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * The two "real security weight" gaps from the panel audit: two-factor
 * authentication for admins, and a role split so not every admin can
 * change plans or manage other admins.
 */
class PlatformAdminSecurityTest extends TestCase
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

    protected function makeCompany(): Company
    {
        $owner = User::factory()->create();
        $company = Company::create([
            'slug' => 'acme-'.uniqid(),
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ]);
        $this->joinCompany($company, $owner, Role::OWNER);

        return $company;
    }

    protected function currentOtp(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    // ---- two-factor: enrolment --------------------------------------------

    public function test_admin_can_enrol_confirm_and_see_recovery_codes(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/settings')
            ->assertSee('Set up two-factor');

        $this->actingAs($this->admin, 'admin')->post('/admin/settings/two-factor/start');
        $this->admin->refresh();
        $this->assertNotNull($this->admin->twoFactorSecret());
        $this->assertFalse($this->admin->hasTwoFactorEnabled());

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/settings')
            ->assertSee('Turn on');

        $code = $this->currentOtp($this->admin->twoFactorSecret());

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/settings/two-factor/confirm', ['code' => $code])
            ->assertRedirect();

        $this->admin->refresh();
        $this->assertTrue($this->admin->hasTwoFactorEnabled());
        $this->assertCount(8, $this->admin->recoveryCodes());
    }

    public function test_wrong_code_does_not_confirm_enrolment(): void
    {
        $this->actingAs($this->admin, 'admin')->post('/admin/settings/two-factor/start');

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/settings/two-factor/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($this->admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_admin_can_disable_two_factor(): void
    {
        $twoFactor = app(TwoFactor::class);
        $twoFactor->startEnrolment($this->admin);
        $twoFactor->confirm($this->admin, $this->currentOtp($this->admin->twoFactorSecret()));
        $this->assertTrue($this->admin->hasTwoFactorEnabled());

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/settings/two-factor/disable')
            ->assertRedirect();

        $this->assertFalse($this->admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_qr_endpoint_404s_with_no_pending_secret(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get('/admin/settings/two-factor/qr.svg')
            ->assertNotFound();
    }

    // ---- two-factor: login challenge --------------------------------------

    public function test_login_with_two_factor_enabled_requires_the_challenge(): void
    {
        $twoFactor = app(TwoFactor::class);
        $twoFactor->startEnrolment($this->admin);
        $twoFactor->confirm($this->admin, $this->currentOtp($this->admin->twoFactorSecret()));

        $this->post('/admin/login', ['email' => 'admin@opes360.com', 'password' => 'password'])
            ->assertRedirect(route('admin.two-factor.challenge'));

        $this->assertGuest('admin');

        $code = $this->currentOtp($this->admin->fresh()->twoFactorSecret());

        $this->post('/admin/two-factor', ['code' => $code])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($this->admin, 'admin');
    }

    public function test_a_recovery_code_works_once(): void
    {
        $twoFactor = app(TwoFactor::class);
        $twoFactor->startEnrolment($this->admin);
        $twoFactor->confirm($this->admin, $this->currentOtp($this->admin->twoFactorSecret()));
        $recoveryCode = $this->admin->fresh()->recoveryCodes()[0];

        $this->post('/admin/login', ['email' => 'admin@opes360.com', 'password' => 'password']);
        $this->post('/admin/two-factor', ['code' => $recoveryCode])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($this->admin, 'admin');

        auth('admin')->logout();

        // The same code again must fail — it was consumed.
        $this->post('/admin/login', ['email' => 'admin@opes360.com', 'password' => 'password']);
        $this->post('/admin/two-factor', ['code' => $recoveryCode])
            ->assertSessionHasErrors('code');
        $this->assertGuest('admin');
    }

    public function test_wrong_challenge_code_is_rejected_and_throttled(): void
    {
        $twoFactor = app(TwoFactor::class);
        $twoFactor->startEnrolment($this->admin);
        $twoFactor->confirm($this->admin, $this->currentOtp($this->admin->twoFactorSecret()));

        $this->post('/admin/login', ['email' => 'admin@opes360.com', 'password' => 'password']);

        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/two-factor', ['code' => '000000']);
        }

        $this->post('/admin/two-factor', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest('admin');
    }

    // ---- roles: support vs admin -------------------------------------------

    public function test_support_admin_can_suspend_but_not_change_plan(): void
    {
        $support = PlatformAdmin::create([
            'name' => 'Support', 'email' => 'support@opes360.com',
            'password' => Hash::make('password'), 'role' => PlatformAdmin::ROLE_SUPPORT,
        ]);
        $company = $this->makeCompany();

        $this->actingAs($support, 'admin')
            ->post("/admin/companies/{$company->slug}/suspend")
            ->assertRedirect();
        $this->assertTrue($company->fresh()->isSuspended());

        $this->actingAs($support, 'admin')
            ->post("/admin/companies/{$company->slug}/plan", ['plan' => 'business'])
            ->assertForbidden();
        $this->assertNotSame('business', $company->fresh()->plan);
    }

    public function test_support_admin_cannot_invite_or_revoke_admins(): void
    {
        $support = PlatformAdmin::create([
            'name' => 'Support', 'email' => 'support@opes360.com',
            'password' => Hash::make('password'), 'role' => PlatformAdmin::ROLE_SUPPORT,
        ]);

        $this->actingAs($support, 'admin')
            ->post('/admin/admins', ['name' => 'X', 'email' => 'x@example.com', 'role' => PlatformAdmin::ROLE_SUPPORT])
            ->assertForbidden();

        $this->actingAs($support, 'admin')
            ->delete('/admin/admins/'.$this->admin->id)
            ->assertForbidden();

        // Read access stays open to support.
        $this->actingAs($support, 'admin')
            ->get('/admin/admins')
            ->assertOk();
    }

    public function test_full_admin_can_do_everything_support_cannot(): void
    {
        $company = $this->makeCompany();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/plan", ['plan' => 'business'])
            ->assertRedirect();
        $this->assertSame('business', $company->fresh()->plan);

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/admins', ['name' => 'X', 'email' => 'x@example.com', 'role' => PlatformAdmin::ROLE_SUPPORT])
            ->assertRedirect();
    }

    public function test_company_show_page_hides_plan_form_for_support(): void
    {
        $support = PlatformAdmin::create([
            'name' => 'Support', 'email' => 'support@opes360.com',
            'password' => Hash::make('password'), 'role' => PlatformAdmin::ROLE_SUPPORT,
        ]);
        $company = $this->makeCompany();

        $this->actingAs($support, 'admin')
            ->get('/admin/companies/'.$company->slug)
            ->assertOk()
            ->assertDontSee('name="plan"', false)
            ->assertSee('needs the full Admin role');
    }
}
