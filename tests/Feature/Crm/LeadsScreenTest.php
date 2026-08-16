<?php

namespace Tests\Feature\Crm;

use App\Livewire\Leads\Index as LeadsIndex;
use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\Role;
use App\Models\User;
use App\Services\DealPipeline;
use App\Services\LeadFunnel;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The leads register: leads, planned activity, and the forecast, on tabs.
 *
 * Screen tests reach the same services the tests next door prove directly, so
 * the interesting assertions here are wiring and permission ones.
 */
class LeadsScreenTest extends TestCase
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

    public function test_the_register_lists_leads(): void
    {
        app(LeadFunnel::class)->create(['name' => 'Jane Mbeki', 'source' => 'referral'], $this->owner);

        Livewire::actingAs($this->owner)
            ->test(LeadsIndex::class)
            ->assertSee('Jane Mbeki');
    }

    public function test_the_form_adds_a_lead_through_the_service(): void
    {
        Livewire::actingAs($this->owner)
            ->test(LeadsIndex::class)
            ->set('adding', true)
            ->set('leadName', 'Paul Etame')
            ->set('leadPhone', '+237699000000')
            ->set('leadSource', 'walk-in')
            ->call('addLead')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('leads', [
            'name' => 'Paul Etame',
            'status' => 'new',
            'source' => 'walk-in',
        ]);
    }

    public function test_converting_from_the_screen_creates_the_contact(): void
    {
        $lead = app(LeadFunnel::class)->create(['name' => 'Ets. Nguemo'], $this->owner);

        Livewire::actingAs($this->owner)
            ->test(LeadsIndex::class)
            ->call('startConvert', $lead->id)
            ->call('convert')
            ->assertHasNoErrors();

        $lead->refresh();

        $this->assertSame('converted', $lead->status);
        $this->assertNotNull($lead->contact_id);
    }

    public function test_converting_with_a_deal_puts_it_on_the_board(): void
    {
        $lead = app(LeadFunnel::class)->create(['name' => 'Ets. Nguemo'], $this->owner);

        Livewire::actingAs($this->owner)
            ->test(LeadsIndex::class)
            ->call('startConvert', $lead->id)
            ->set('withDeal', true)
            ->set('dealTitle', 'Supply cement')
            ->set('dealValue', '750000')
            ->call('convert')
            ->assertHasNoErrors();

        $this->assertNotNull($lead->fresh()->deal_id);
        $this->assertDatabaseHas('deals', ['title' => 'Supply cement']);
    }

    public function test_losing_from_the_screen_wants_a_reason(): void
    {
        $lead = app(LeadFunnel::class)->create(['name' => 'Jane Mbeki'], $this->owner);

        Livewire::actingAs($this->owner)
            ->test(LeadsIndex::class)
            ->call('startLose', $lead->id)
            ->call('lose')
            ->assertHasErrors(['lostReason']);

        Livewire::actingAs($this->owner)
            ->test(LeadsIndex::class)
            ->call('startLose', $lead->id)
            ->set('lostReason', 'No budget this year')
            ->call('lose')
            ->assertHasNoErrors();

        $this->assertSame('No budget this year', $lead->fresh()->lost_reason);
    }

    public function test_an_activity_can_be_logged_against_a_deal_from_the_screen(): void
    {
        $deal = app(DealPipeline::class)->create([
            'title' => 'Supply cement', 'lead_name' => 'Jane', 'value' => 100,
        ], $this->owner);

        Livewire::actingAs($this->owner)
            ->test(LeadsIndex::class)
            ->set('tab', 'activity')
            ->set('actSubject', 'deal:'.$deal->id)
            ->set('actKind', 'call')
            ->set('actSummary', 'Chase the quotation')
            ->set('actDueAt', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('logActivity')
            ->assertHasNoErrors();

        $this->assertSame(1, $deal->activities()->count());
    }

    public function test_completing_an_activity_from_the_screen(): void
    {
        $deal = app(DealPipeline::class)->create([
            'title' => 'Supply cement', 'lead_name' => 'Jane', 'value' => 100,
        ], $this->owner);
        $activity = CrmActivity::log($deal, 'task', 'Send proposal', $this->owner, dueAt: now()->subDay());

        Livewire::actingAs($this->owner)
            ->test(LeadsIndex::class)
            ->call('completeActivity', $activity->id);

        $this->assertNotNull($activity->fresh()->done_at);
    }

    public function test_a_cashier_cannot_open_the_register(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Livewire::actingAs($cashier)->test(LeadsIndex::class)->assertForbidden();
    }
}
