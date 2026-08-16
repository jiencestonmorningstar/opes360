<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\LeadFunnel;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Leads and what becomes of them.
 *
 * The rule the whole feature stands on: converting a lead writes into the
 * EXISTING customer book and the EXISTING pipeline — a Contact and, if asked, a
 * Deal — and the lead row survives with the links, so the trail from "someone
 * gave us a number at a trade fair" to "customer with an invoice" is walkable.
 */
class LeadFunnelTest extends TestCase
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

    protected function makeLead(array $attributes = []): Lead
    {
        return app(LeadFunnel::class)->create(array_merge([
            'name' => 'Jane Mbeki',
            'phone' => '+237699111222',
            'source' => 'referral',
        ], $attributes), $this->owner);
    }

    public function test_a_new_lead_starts_as_new_and_belongs_to_its_creator(): void
    {
        $lead = $this->makeLead();

        $this->assertSame('new', $lead->status);
        $this->assertSame($this->owner->id, $lead->assigned_to);
        $this->assertSame($this->company->id, $lead->company_id);
        $this->assertTrue($lead->isOpen());
    }

    public function test_a_lead_can_move_through_working_and_qualified(): void
    {
        $lead = $this->makeLead();

        app(LeadFunnel::class)->moveTo($lead, 'working');
        $this->assertSame('working', $lead->fresh()->status);

        app(LeadFunnel::class)->moveTo($lead, 'qualified');
        $this->assertSame('qualified', $lead->fresh()->status);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $lead = $this->makeLead();

        $this->expectException(InvalidArgumentException::class);

        app(LeadFunnel::class)->moveTo($lead, 'banana');
    }

    public function test_converted_and_lost_cannot_be_reached_by_a_plain_move(): void
    {
        $lead = $this->makeLead();

        $this->expectException(InvalidArgumentException::class);

        app(LeadFunnel::class)->moveTo($lead, 'converted');
    }

    public function test_converting_creates_a_contact_and_keeps_the_lead(): void
    {
        $lead = $this->makeLead(['name' => 'Ets. Nguemo', 'email' => 'nguemo@example.test']);

        $contact = app(LeadFunnel::class)->convert($lead, $this->owner);

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame('customer', $contact->type);
        $this->assertSame('Ets. Nguemo', $contact->name);
        $this->assertSame(['+237699111222'], $contact->phones);

        $lead->refresh();

        // The lead survives as the record of where the customer came from.
        $this->assertSame('converted', $lead->status);
        $this->assertSame($contact->id, $lead->contact_id);
        $this->assertNotNull($lead->converted_at);
        $this->assertDatabaseHas('leads', ['id' => $lead->id]);
    }

    public function test_converting_with_a_deal_goes_through_the_existing_pipeline(): void
    {
        $lead = $this->makeLead();

        app(LeadFunnel::class)->convert($lead, $this->owner, deal: [
            'title' => 'Supply cement',
            'value' => 500000,
        ]);

        $lead->refresh();
        $deal = Deal::findOrFail($lead->deal_id);

        // The deal is a normal pipeline deal, already tied to the new contact —
        // not a parallel object the board cannot see.
        $this->assertSame($lead->contact_id, $deal->contact_id);
        $this->assertSame('lead', $deal->stage);
        $this->assertEquals(500000, (float) $deal->value);
    }

    public function test_a_lead_cannot_be_converted_twice(): void
    {
        $lead = $this->makeLead();
        app(LeadFunnel::class)->convert($lead, $this->owner);

        $this->expectException(RuntimeException::class);

        app(LeadFunnel::class)->convert($lead->fresh(), $this->owner);
    }

    public function test_losing_a_lead_records_why(): void
    {
        $lead = $this->makeLead();

        app(LeadFunnel::class)->lose($lead, 'Went with a competitor');

        $lead->refresh();

        $this->assertSame('lost', $lead->status);
        $this->assertSame('Went with a competitor', $lead->lost_reason);
        $this->assertFalse($lead->isOpen());
    }

    public function test_a_lost_lead_cannot_be_converted(): void
    {
        $lead = $this->makeLead();
        app(LeadFunnel::class)->lose($lead, 'No budget');

        $this->expectException(RuntimeException::class);

        app(LeadFunnel::class)->convert($lead->fresh(), $this->owner);
    }

    public function test_a_converted_lead_cannot_be_lost(): void
    {
        $lead = $this->makeLead();
        app(LeadFunnel::class)->convert($lead, $this->owner);

        $this->expectException(RuntimeException::class);

        app(LeadFunnel::class)->lose($lead->fresh(), 'Changed their mind');
    }
}
