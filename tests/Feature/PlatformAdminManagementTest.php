<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Document;
use App\Models\PlatformAdmin;
use App\Models\PlatformAdminActivity;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CompanyPlanChangedNotification;
use App\Notifications\CompanyReactivatedNotification;
use App\Notifications\CompanySuspendedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The functional/UX round of the admin panel audit: member management,
 * inviting/revoking other admins, the platform-wide activity log, and the
 * owner-facing notifications that fire on suspend/reactivate/plan-change.
 */
class PlatformAdminManagementTest extends TestCase
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

    protected function makeCompanyWithOwner(): array
    {
        $owner = User::factory()->create();
        $company = Company::create([
            'slug' => 'acme-'.uniqid(),
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ]);
        $this->joinCompany($company, $owner, Role::OWNER);

        return [$company, $owner];
    }

    // ---- company drill-down ------------------------------------------------

    public function test_company_show_page_lists_recent_documents_and_module_counts(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner();

        app(\App\Support\CurrentCompany::class)->set($company);
        Document::create([
            'type' => DocumentType::Invoice,
            'status' => DocumentStatus::Issued,
            'number' => 'INV-0001',
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 100,
            'total' => 100,
            'balance' => 100,
        ]);
        app(\App\Support\CurrentCompany::class)->set(null);

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/companies/'.$company->slug)
            ->assertOk()
            ->assertSee('INV-0001')
            ->assertSee('Recent documents');
    }

    // ---- member management -------------------------------------------------

    public function test_admin_can_remove_a_member_but_not_the_owner(): void
    {
        [$company, $owner] = $this->makeCompanyWithOwner();
        $staff = User::factory()->create();
        $this->joinCompany($company, $staff, Role::SALES_OFFICER);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/members/{$staff->id}/remove")
            ->assertRedirect();

        $this->assertFalse($company->fresh()->users()->whereKey($staff->id)->exists());

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/members/{$owner->id}/remove")
            ->assertRedirect();

        $this->assertTrue($company->fresh()->users()->whereKey($owner->id)->exists());
    }

    public function test_admin_can_trigger_a_password_reset_for_a_member(): void
    {
        Notification::fake();

        [$company, $owner] = $this->makeCompanyWithOwner();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/members/{$owner->id}/reset-password")
            ->assertRedirect();

        Notification::assertSentTo($owner, \App\Notifications\ResetPassword::class);
        $this->assertDatabaseHas('platform_admin_activity', [
            'action' => 'sent_password_reset',
            'subject_id' => $company->id,
        ]);
        $entry = PlatformAdminActivity::where('action', 'sent_password_reset')->first();
        $this->assertSame($owner->email, $entry->meta['user_email']);
    }

    public function test_a_member_action_on_an_unrelated_company_404s(): void
    {
        [$company] = $this->makeCompanyWithOwner();
        $outsider = User::factory()->create();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/members/{$outsider->id}/remove")
            ->assertNotFound();
    }

    // ---- notifications to the business --------------------------------------

    public function test_owner_is_notified_when_admin_suspends_reactivates_or_changes_plan(): void
    {
        Notification::fake();

        [$company, $owner] = $this->makeCompanyWithOwner();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/suspend", ['reason' => 'Non-payment']);
        Notification::assertSentTo($owner, CompanySuspendedNotification::class);
        $this->assertDatabaseHas('platform_admin_activity', [
            'action' => 'suspended_company',
            'subject_id' => $company->id,
        ]);
        $entry = PlatformAdminActivity::where('action', 'suspended_company')->first();
        $this->assertSame('Non-payment', $entry->meta['reason']);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/activate");
        Notification::assertSentTo($owner, CompanyReactivatedNotification::class);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/plan", ['plan' => 'business']);
        Notification::assertSentTo($owner, CompanyPlanChangedNotification::class);
    }

    public function test_no_plan_notification_when_the_plan_does_not_actually_change(): void
    {
        Notification::fake();

        [$company] = $this->makeCompanyWithOwner();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/plan", ['plan' => $company->plan]);

        Notification::assertNothingSent();
    }

    // ---- managing other admins -----------------------------------------------

    public function test_admin_can_invite_a_new_admin(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'admin')
            ->post('/admin/admins', ['name' => 'New Admin', 'email' => 'new-admin@example.com'])
            ->assertRedirect();

        $newAdmin = PlatformAdmin::where('email', 'new-admin@example.com')->first();
        $this->assertNotNull($newAdmin);

        Notification::assertSentTo($newAdmin, \App\Notifications\AdminResetPassword::class);
        $this->assertDatabaseHas('platform_admin_activity', [
            'action' => 'invited_admin',
            'subject_id' => $newAdmin->id,
        ]);
    }

    public function test_admin_cannot_revoke_their_own_access(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->delete('/admin/admins/'.$this->admin->id)
            ->assertRedirect();

        $this->assertNull($this->admin->fresh()->deleted_at);
    }

    public function test_admin_can_revoke_another_admin_and_they_can_no_longer_log_in(): void
    {
        $other = PlatformAdmin::create([
            'name' => 'Other Admin',
            'email' => 'other@opes360.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($this->admin, 'admin')
            ->delete('/admin/admins/'.$other->id)
            ->assertRedirect();

        $this->assertSoftDeleted($other);

        // actingAs() leaves the 'admin' guard authenticated as $this->admin
        // for subsequent requests in this test; log out so the next request
        // genuinely attempts an unauthenticated login as $other.
        auth('admin')->logout();

        $this->post('/admin/login', ['email' => 'other@opes360.com', 'password' => 'password'])
            ->assertSessionHasErrors();
        $this->assertGuest('admin');
    }

    public function test_revoking_an_admin_does_not_erase_their_past_activity(): void
    {
        [$company] = $this->makeCompanyWithOwner();

        $other = PlatformAdmin::create([
            'name' => 'Other Admin',
            'email' => 'other@opes360.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($other, 'admin')->post("/admin/companies/{$company->slug}/suspend");

        $this->actingAs($this->admin, 'admin')->delete('/admin/admins/'.$other->id);

        $this->assertDatabaseHas('platform_admin_activity', [
            'platform_admin_id' => $other->id,
            'action' => 'suspended_company',
        ]);
    }

    public function test_platform_activity_log_lists_actions_across_companies(): void
    {
        [$company] = $this->makeCompanyWithOwner();

        $this->actingAs($this->admin, 'admin')->post("/admin/companies/{$company->slug}/suspend");

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/activity')
            ->assertOk()
            ->assertSee('suspended company')
            ->assertSee($company->name);
    }

    // ---- cross-system: the business-side audit trail must survive an admin acting ----

    public function test_a_platform_admin_write_does_not_crash_the_business_audit_log(): void
    {
        // Regression: AuditObserver used to call the bare auth()->id(), which
        // resolves against whatever guard happens to be "default" for the
        // request. Under actingAs('admin') that's the platform admin's own
        // id — which isn't a row in `users` — and activity_log.user_id has a
        // foreign key to `users`, so this used to 500 with a constraint
        // violation on every suspend/activate/plan-change.
        [$company] = $this->makeCompanyWithOwner();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/suspend")
            ->assertRedirect();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'user_id' => null,
        ]);

        // Company creation itself also logs a 'created' row (with no
        // platform_admin — no admin session exists at that point in the
        // test), so the 'updated' row from the suspend action is picked
        // explicitly rather than relying on latest() to break the tie.
        $entry = \App\Models\ActivityLog::where('subject_id', $company->id)->where('event', 'updated')->first();
        $this->assertSame($this->admin->email, $entry->properties['platform_admin']);
    }
}
