<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Deal;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The pipeline over the token API.
 *
 * The load-bearing behaviour here is not that the endpoints work — it is that
 * they refuse the same things the UI refuses. A token is not a way past the
 * module switch, the permission catalogue, or the tenant boundary, and each of
 * those is asserted below rather than assumed from the middleware list.
 */
class DealsApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = $this->makeCompany('Acme Sarl', $this->owner);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
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

    public function test_a_token_can_be_issued_and_identifies_its_user(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);

        $this->postJson('/api/tokens', [
            'email' => $user->email,
            'password' => 'secret-password',
            'device_name' => 'phpunit',
        ])->assertCreated()->assertJsonStructure(['token']);
    }

    public function test_bad_credentials_do_not_issue_a_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('secret-password')]);

        $this->postJson('/api/tokens', [
            'email' => $user->email,
            'password' => 'not-the-password',
            'device_name' => 'phpunit',
        ])->assertStatus(422);
    }

    public function test_the_api_refuses_an_unauthenticated_caller(): void
    {
        $this->getJson('/api/deals')->assertUnauthorized();
    }

    public function test_a_deal_can_be_created_and_read_back(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/deals', [
            'title' => 'Supply 200 bags cement',
            'lead_name' => 'Jane Mbeki',
            'value' => 1250000,
            'expected_close_on' => '2026-09-15',
        ])->assertCreated();

        $id = $response->json('data.id');

        $this->getJson("/api/deals/{$id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Supply 200 bags cement')
            ->assertJsonPath('data.stage', 'lead')
            ->assertJsonPath('data.display_name', 'Jane Mbeki')
            ->assertJsonPath('data.is_open', true);
    }

    public function test_a_deal_needs_a_contact_or_a_lead_name(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/deals', ['title' => 'Nameless'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contact_id', 'lead_name']);
    }

    public function test_winning_a_deal_closes_it_and_reopening_clears_the_closure(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/deals', [
            'title' => 'Roof sheets',
            'lead_name' => 'Paul',
        ])->json('data.id');

        $this->postJson("/api/deals/{$id}/move", ['stage' => 'won'])
            ->assertOk()
            ->assertJsonPath('data.stage', 'won')
            ->assertJsonPath('data.is_open', false);

        $this->assertNotNull(Deal::find($id)->closed_at);

        // The reopening case: a deal that claims it was settled on a date it
        // is plainly still open past is worse than one with no date at all.
        $this->postJson("/api/deals/{$id}/move", ['stage' => 'proposal'])
            ->assertOk()
            ->assertJsonPath('data.is_open', true)
            ->assertJsonPath('data.closed_at', null);
    }

    public function test_losing_a_deal_keeps_the_reason_and_winning_drops_it(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/deals', [
            'title' => 'Tiles',
            'lead_name' => 'Ada',
        ])->json('data.id');

        $this->postJson("/api/deals/{$id}/move", [
            'stage' => 'lost',
            'lost_reason' => 'Bought from a competitor',
        ])->assertJsonPath('data.lost_reason', 'Bought from a competitor');

        $this->postJson("/api/deals/{$id}/move", ['stage' => 'won'])
            ->assertJsonPath('data.lost_reason', null);
    }

    public function test_an_unknown_stage_is_rejected(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/deals', [
            'title' => 'Sand',
            'lead_name' => 'Sam',
        ])->json('data.id');

        $this->postJson("/api/deals/{$id}/move", ['stage' => 'banana'])
            ->assertStatus(422);
    }

    public function test_the_open_filter_excludes_closed_deals(): void
    {
        Sanctum::actingAs($this->owner);

        $open = $this->postJson('/api/deals', ['title' => 'Open one', 'lead_name' => 'A'])->json('data.id');
        $closed = $this->postJson('/api/deals', ['title' => 'Closed one', 'lead_name' => 'B'])->json('data.id');

        $this->postJson("/api/deals/{$closed}/move", ['stage' => 'won']);

        $ids = collect($this->getJson('/api/deals?open=1')->json('data'))->pluck('id');

        $this->assertContains($open, $ids);
        $this->assertNotContains($closed, $ids);
    }

    public function test_a_deal_can_be_deleted(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/deals', ['title' => 'Gone', 'lead_name' => 'C'])->json('data.id');

        $this->deleteJson("/api/deals/{$id}")->assertNoContent();

        $this->assertSoftDeleted('deals', ['id' => $id]);
    }

    public function test_a_won_deal_can_be_invoiced_over_the_api(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/deals', [
            'title' => 'Roof sheets',
            'lead_name' => 'Paul',
            'value' => 750000,
        ])->json('data.id');

        $this->postJson("/api/deals/{$id}/move", ['stage' => 'won']);

        $this->postJson("/api/deals/{$id}/invoice")
            ->assertCreated()
            // Draft on purpose: issuing is immutable and enters the books.
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total', fn ($total) => (float) $total === 750000.0);

        $this->assertNotNull(Deal::find($id)->document_id);
    }

    public function test_an_open_deal_cannot_be_invoiced_over_the_api(): void
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/deals', ['title' => 'Still open', 'lead_name' => 'Ada'])->json('data.id');

        $this->postJson("/api/deals/{$id}/invoice")->assertStatus(422);
    }

    public function test_the_module_switch_denies_the_api_too(): void
    {
        $this->company->forceFill(['modules' => ['deals' => false]])->save();

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/deals', ['title' => 'Nope', 'lead_name' => 'D'])
            ->assertForbidden();
    }

    public function test_a_cashier_cannot_touch_the_pipeline(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($cashier);

        $this->getJson('/api/deals')->assertForbidden();
    }

    public function test_a_deal_from_another_company_is_not_reachable(): void
    {
        // Someone else's pipeline, created in their own tenant context.
        $stranger = User::factory()->create();
        $other = $this->makeCompany('Other Sarl', $stranger);
        $this->joinCompany($other, $stranger, Role::OWNER);

        app(CurrentCompany::class)->set($other);
        $theirDeal = Deal::create([
            'company_id' => $other->id,
            'title' => 'Not yours',
            'lead_name' => 'Stranger',
            'stage' => 'lead',
        ]);
        app(CurrentCompany::class)->set($this->company);

        Sanctum::actingAs($this->owner);

        // 404 rather than 403: the tenant scope means the row does not exist
        // for this caller, which is the right thing to tell them.
        $this->getJson("/api/deals/{$theirDeal->id}")->assertNotFound();
    }
}
