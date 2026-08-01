<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The lower-priority round of the admin panel audit: demo/trial
 * extension, persistent notes, usage visibility, CSV export, activity log
 * filters, and the dashboard signups chart.
 */
class PlatformAdminExtrasTest extends TestCase
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
        $company = Company::create(array_merge([
            'slug' => 'acme-'.uniqid(),
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ], $overrides));
        $this->joinCompany($company, $owner, Role::OWNER);

        return $company;
    }

    // ---- demo/trial extension ------------------------------------------

    public function test_admin_can_extend_a_demo(): void
    {
        $company = $this->makeCompany(['account_type' => 'demo', 'demo_expires_at' => now()->addDays(2)]);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/extend-demo", ['days' => 14])
            ->assertRedirect();

        $company->refresh();
        $this->assertTrue($company->demo_expires_at->isAfter(now()->addDays(15)));
        $this->assertDatabaseHas('platform_admin_activity', ['action' => 'extended_demo', 'subject_id' => $company->id]);
    }

    public function test_extending_an_already_expired_demo_starts_from_now(): void
    {
        $company = $this->makeCompany(['account_type' => 'demo', 'demo_expires_at' => now()->subDays(5)]);

        $this->actingAs($this->admin, 'admin')->post("/admin/companies/{$company->slug}/extend-demo", ['days' => 7]);

        $company->refresh();
        $this->assertTrue($company->demo_expires_at->isAfter(now()->addDays(6)));
    }

    public function test_admin_can_end_a_demo_early(): void
    {
        $company = $this->makeCompany(['account_type' => 'demo', 'demo_expires_at' => now()->addDays(10)]);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/end-demo")
            ->assertRedirect();

        $company->refresh();
        $this->assertSame('trial', $company->account_type);
        $this->assertNull($company->demo_expires_at);
    }

    public function test_demo_actions_404_for_a_non_demo_company(): void
    {
        $company = $this->makeCompany(['account_type' => 'active']);

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/extend-demo", ['days' => 7])
            ->assertNotFound();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/end-demo")
            ->assertNotFound();
    }

    // ---- persistent notes -----------------------------------------------

    public function test_admin_can_add_and_see_a_note(): void
    {
        $company = $this->makeCompany();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/notes", ['body' => 'Spoke with the owner, promised payment by Friday.'])
            ->assertRedirect();

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/companies/'.$company->slug)
            ->assertSee('Spoke with the owner, promised payment by Friday.')
            ->assertSee($this->admin->name);
    }

    public function test_a_note_requires_a_body(): void
    {
        $company = $this->makeCompany();

        $this->actingAs($this->admin, 'admin')
            ->post("/admin/companies/{$company->slug}/notes", ['body' => ''])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('company_notes', 0);
    }

    // ---- usage visibility -------------------------------------------------

    public function test_company_show_page_lists_usage_counts(): void
    {
        $company = $this->makeCompany();

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/companies/'.$company->slug)
            ->assertOk()
            ->assertSee('Contacts')
            ->assertSee('Items');
    }

    // ---- CSV export ---------------------------------------------------------

    public function test_export_respects_the_current_filter(): void
    {
        $this->makeCompany(['name' => 'Demo Co', 'account_type' => 'demo']);
        $this->makeCompany(['name' => 'Active Co', 'account_type' => 'active']);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/companies/export?status=demo');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Demo Co', $csv);
        $this->assertStringNotContainsString('Active Co', $csv);
    }

    // ---- activity log filters ------------------------------------------

    public function test_activity_log_can_be_filtered_by_admin_and_action(): void
    {
        $other = PlatformAdmin::create(['name' => 'Other', 'email' => 'other@opes360.com', 'password' => Hash::make('password')]);
        $companyA = $this->makeCompany(['name' => 'Company A']);
        $companyB = $this->makeCompany(['name' => 'Company B']);

        $this->actingAs($this->admin, 'admin')->post("/admin/companies/{$companyA->slug}/suspend");
        $this->actingAs($other, 'admin')->post("/admin/companies/{$companyB->slug}/suspend");
        $this->actingAs($other, 'admin')->post("/admin/companies/{$companyB->slug}/activate");

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/activity?admin='.$other->id)
            ->assertOk()
            ->assertSee('Company B')
            ->assertDontSee('Company A');

        // Not assertDontSee('suspended company') — the filter dropdown itself
        // always lists every distinct action as an <option>, so that text is
        // present on the page regardless of which filter is selected.
        $response = $this->actingAs($this->admin, 'admin')->get('/admin/activity?action=reactivated_company');
        $response->assertOk();
        $response->assertViewHas('activity', function ($activity) {
            return $activity->count() === 1 && $activity->first()->action === 'reactivated_company';
        });
    }

    // ---- dashboard signups chart -------------------------------------------

    public function test_dashboard_shows_the_weekly_signups_chart(): void
    {
        $this->makeCompany();

        $this->actingAs($this->admin, 'admin')
            ->get('/admin')
            ->assertOk()
            ->assertSee('New businesses per week');
    }
}
