<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\Deal;
use App\Models\Role;
use App\Models\User;
use App\Services\DealPipeline;
use App\Services\LeadFunnel;
use App\Support\CurrentCompany;
use App\Support\SalesForecast;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The planned side of CRM: calls to make, meetings held, tasks due.
 *
 * Deliberately NOT the audit trail. AuditObserver already records every write
 * to a Deal; what it cannot know is what somebody INTENDS to do next. These
 * rows are the intentions, and "which deals has nobody touched" is the query
 * they exist to answer.
 */
class CrmActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
    }

    protected function makeDeal(array $attributes = []): Deal
    {
        return app(DealPipeline::class)->create(array_merge([
            'title' => 'Supply cement',
            'lead_name' => 'Jane Mbeki',
            'value' => 500000,
        ], $attributes), $this->owner);
    }

    public function test_a_note_against_a_deal_is_done_the_moment_it_is_written(): void
    {
        $deal = $this->makeDeal();

        $activity = CrmActivity::log($deal, 'note', 'Spoke to Jane, wants a site visit', $this->owner);

        $this->assertNotNull($activity->done_at);
        $this->assertSame($this->owner->id, $activity->user_id);
        $this->assertTrue($deal->activities()->whereKey($activity->id)->exists());
    }

    public function test_a_planned_call_is_open_until_completed(): void
    {
        $deal = $this->makeDeal();

        $activity = CrmActivity::log($deal, 'call', 'Chase the quotation', $this->owner, dueAt: now()->addDay());

        $this->assertNull($activity->done_at);
        $this->assertTrue(CrmActivity::planned()->whereKey($activity->id)->exists());

        $activity->complete();

        $this->assertNotNull($activity->fresh()->done_at);
        $this->assertFalse(CrmActivity::planned()->whereKey($activity->id)->exists());
    }

    public function test_overdue_means_planned_and_past_due(): void
    {
        $deal = $this->makeDeal();

        $late = CrmActivity::log($deal, 'task', 'Send the proposal', $this->owner, dueAt: now()->subDays(2));
        $future = CrmActivity::log($deal, 'call', 'Follow up next week', $this->owner, dueAt: now()->addWeek());
        $doneLate = CrmActivity::log($deal, 'task', 'Already handled', $this->owner, dueAt: now()->subDays(5));
        $doneLate->complete();

        $overdue = CrmActivity::overdue()->pluck('id');

        $this->assertTrue($overdue->contains($late->id));
        $this->assertFalse($overdue->contains($future->id));
        $this->assertFalse($overdue->contains($doneLate->id));
    }

    public function test_activities_attach_to_leads_as_well(): void
    {
        $lead = app(LeadFunnel::class)->create([
            'name' => 'Paul Etame',
            'phone' => '+237699000000',
        ], $this->owner);

        CrmActivity::log($lead, 'meeting', 'Met at the trade fair', $this->owner);

        $this->assertSame(1, $lead->activities()->count());
    }

    public function test_untouched_deals_are_the_open_ones_with_no_recent_activity(): void
    {
        $stale = $this->makeDeal(['title' => 'Forgotten deal']);
        $active = $this->makeDeal(['title' => 'Worked deal']);
        $closed = $this->makeDeal(['title' => 'Closed deal']);
        app(DealPipeline::class)->moveTo($closed, 'won');

        // Age everything past the window, then touch only one of them.
        Deal::query()->update(['updated_at' => now()->subDays(20)]);
        CrmActivity::log($active, 'call', 'Rang this morning', $this->owner);

        $untouched = (new SalesForecast)->untouchedDeals(7)->pluck('title');

        $this->assertTrue($untouched->contains('Forgotten deal'));
        $this->assertFalse($untouched->contains('Worked deal'));
        // A closed deal needs no touching; it is finished.
        $this->assertFalse($untouched->contains('Closed deal'));
    }
}
