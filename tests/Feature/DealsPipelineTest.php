<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Livewire\Deals\Form as DealForm;
use App\Livewire\Deals\Index as DealsIndex;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\DealPipeline;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The pipeline board and its form.
 *
 * The board and the API share DealPipeline on purpose, so the interesting
 * assertions here are the ones the API tests cannot make: that the screen
 * reaches the same service, and that a role without the permission never sees
 * the controls in the first place.
 */
class DealsPipelineTest extends TestCase
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

    public function test_the_board_renders_with_the_open_stages(): void
    {
        $this->makeDeal();

        $this->actingAs($this->owner)
            ->get(route('deals'))
            ->assertOk()
            ->assertSee('Pipeline')
            ->assertSee('Supply cement')
            // Closed columns stay off the board until asked for.
            ->assertSee('Qualified')
            ->assertDontSee('Nothing here. Won');
    }

    public function test_the_empty_state_explains_what_a_deal_is(): void
    {
        $this->actingAs($this->owner)
            ->get(route('deals'))
            ->assertOk()
            ->assertSee('No deals yet');
    }

    public function test_moving_a_card_goes_through_the_service(): void
    {
        $deal = $this->makeDeal();

        Livewire::actingAs($this->owner)
            ->test(DealsIndex::class)
            ->call('move', $deal->id, 'won');

        $deal->refresh();

        $this->assertSame('won', $deal->stage);
        // The service's rule, reached from the screen rather than duplicated.
        $this->assertNotNull($deal->closed_at);
    }

    public function test_closed_deals_are_hidden_until_asked_for(): void
    {
        $open = $this->makeDeal(['title' => 'Still going']);
        $closed = $this->makeDeal(['title' => 'All done']);

        app(DealPipeline::class)->moveTo($closed, 'won');

        Livewire::actingAs($this->owner)
            ->test(DealsIndex::class)
            ->assertSee('Still going')
            ->assertDontSee('All done')
            ->set('showClosed', true)
            ->assertSee('All done');
    }

    public function test_the_form_creates_a_deal(): void
    {
        Livewire::actingAs($this->owner)
            ->test(DealForm::class)
            ->set('title', 'Roof sheets for Bonabéri')
            ->set('lead_name', 'Paul Etame')
            ->set('value', '750000')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('deals'));

        $this->assertDatabaseHas('deals', [
            'title' => 'Roof sheets for Bonabéri',
            'lead_name' => 'Paul Etame',
            'stage' => 'lead',
        ]);
    }

    public function test_the_form_wants_a_customer_or_a_lead_name(): void
    {
        Livewire::actingAs($this->owner)
            ->test(DealForm::class)
            ->set('title', 'Nameless deal')
            ->call('save')
            ->assertHasErrors(['contact_id', 'lead_name']);
    }

    public function test_a_deal_can_be_attached_to_an_existing_customer(): void
    {
        $contact = Contact::create([
            'company_id' => $this->company->id,
            'type' => 'customer',
            'name' => 'Boulangerie Nkolbisson',
        ]);

        Livewire::actingAs($this->owner)
            ->test(DealForm::class)
            ->set('title', 'Monthly flour supply')
            ->set('contact_id', $contact->id)
            ->call('save')
            ->assertHasNoErrors();

        $deal = Deal::where('title', 'Monthly flour supply')->firstOrFail();

        $this->assertSame($contact->id, $deal->contact_id);
        $this->assertSame('Boulangerie Nkolbisson', $deal->displayName());
    }

    public function test_a_cashier_cannot_open_the_board(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($cashier)
            ->get(route('deals'))
            ->assertForbidden();
    }

    public function test_switching_the_module_off_closes_the_board(): void
    {
        $this->company->forceFill(['modules' => ['deals' => false]])->save();

        $this->actingAs($this->owner)
            ->get(route('deals'))
            ->assertForbidden();
    }

    public function test_a_won_deal_becomes_a_draft_invoice(): void
    {
        $deal = $this->makeDeal(['title' => 'Roof sheets', 'value' => 750000]);
        app(DealPipeline::class)->moveTo($deal, 'won');

        $document = app(DealPipeline::class)->convertToInvoice($deal, $this->owner);

        // Draft, not issued: issuing is immutable and enters the books, and
        // nothing here knows the real line items or the tax treatment.
        $this->assertSame(DocumentStatus::Draft, $document->status);
        $this->assertEquals(750000, $document->total);
        $this->assertSame($document->id, $deal->fresh()->document_id);

        $this->assertDatabaseHas('document_lines', [
            'document_id' => $document->id,
            'description' => 'Roof sheets',
        ]);
    }

    /** The natural moment a lead becomes a customer is when you invoice them. */
    public function test_invoicing_a_bare_lead_creates_the_customer_record(): void
    {
        $deal = $this->makeDeal(['lead_name' => 'Ets. Nguemo', 'lead_phone' => '+237699000000']);
        app(DealPipeline::class)->moveTo($deal, 'won');

        $this->assertNull($deal->contact_id);

        app(DealPipeline::class)->convertToInvoice($deal, $this->owner);

        $contact = Contact::where('name', 'Ets. Nguemo')->firstOrFail();

        $this->assertSame(['+237699000000'], $contact->phones);
        $this->assertSame($contact->id, $deal->fresh()->contact_id);
    }

    public function test_an_open_deal_cannot_be_invoiced(): void
    {
        $deal = $this->makeDeal();

        $this->expectException(RuntimeException::class);

        app(DealPipeline::class)->convertToInvoice($deal, $this->owner);
    }

    public function test_a_deal_cannot_be_invoiced_twice(): void
    {
        $deal = $this->makeDeal();
        app(DealPipeline::class)->moveTo($deal, 'won');
        app(DealPipeline::class)->convertToInvoice($deal, $this->owner);

        $this->expectException(RuntimeException::class);

        app(DealPipeline::class)->convertToInvoice($deal->fresh(), $this->owner);
    }

    /**
     * Invoicing asks for the sales permission on top of the deal one, because
     * it writes into the sales module. The seeded Sales Officer happens to
     * hold both — chasing and billing are not separated by default here — so
     * this proves the second check is real by taking that permission away
     * rather than by assuming a role that lacks it.
     */
    public function test_invoicing_needs_the_sales_permission_not_just_the_deal_one(): void
    {
        $officer = User::factory()->create();
        $this->joinCompany($this->company, $officer, 'sales-officer');
        $officer->forceFill(['current_company_id' => $this->company->id])->save();

        $deal = $this->makeDeal();
        app(DealPipeline::class)->moveTo($deal, 'won');

        // With sales.create, the same call goes through.
        Livewire::actingAs($officer)
            ->test(DealsIndex::class)
            ->call('invoice', $deal->id)
            ->assertHasNoErrors();

        $this->assertNotNull($deal->fresh()->document_id);

        $role = Role::where('slug', 'sales-officer')->firstOrFail();
        $role->permissions()->detach(
            Permission::where('slug', 'sales.create')->value('id')
        );

        $second = $this->makeDeal(['title' => 'Another one']);
        app(DealPipeline::class)->moveTo($second, 'won');

        Livewire::actingAs($officer->fresh())
            ->test(DealsIndex::class)
            ->call('invoice', $second->id)
            ->assertForbidden();
    }

    public function test_the_service_refuses_an_unknown_stage(): void
    {
        $deal = $this->makeDeal();

        $this->expectException(InvalidArgumentException::class);

        app(DealPipeline::class)->moveTo($deal, 'banana');
    }
}
