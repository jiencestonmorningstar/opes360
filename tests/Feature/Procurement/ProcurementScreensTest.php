<?php

namespace Tests\Feature\Procurement;

use App\Enums\DocumentStatus;
use App\Events\DomainEvent;
use App\Listeners\SyncApprovedRequisitions;
use App\Livewire\Procurement\Requisitions as RequisitionsScreen;
use App\Livewire\Procurement\Sourcing as SourcingScreen;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Permission;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Procurement\Requisitions as RequisitionService;
use App\Services\Procurement\Sourcing as SourcingService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two screens over procurement's front half.
 *
 * The tests that matter here are the ones about what the screens must *not*
 * do: no approve control anywhere, no uninvited supplier in a dropdown, and no
 * award without the ability for it. Those are the rules the rest of this
 * module is built to protect, and a screen is the easiest place to lose them.
 */
class ProcurementScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $buyer;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            // The module ships off, so nothing below would be reachable.
            'modules' => ['procurement' => true, 'expenses' => true],
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        // A buyer who runs the RFQs but does not decide who wins them: the
        // separation the `rfq-award` ability exists to express.
        $this->buyer = User::factory()->create();
        $this->joinCompany($this->company, $this->buyer, Role::MANAGER);
        $this->buyer->forceFill(['current_company_id' => $this->company->id])->save();
        $this->grant(Role::MANAGER, [
            'procurement.requisition-view',
            'procurement.requisition-manage',
            'procurement.rfq-view',
            'procurement.rfq-manage',
        ]);

        // Registered here for the same reason as in ProcurementRequisitionTest:
        // the provider line belongs to the integrating agent.
        Event::listen(DomainEvent::class, SyncApprovedRequisitions::class);
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<int, string>  $slugs */
    protected function grant(string $role, array $slugs): void
    {
        $role = Role::where('slug', $role)->firstOrFail();

        $role->permissions()->syncWithoutDetaching(
            Permission::whereIn('slug', $slugs)->pluck('id')->all()
        );
    }

    protected function workflow(): Workflow
    {
        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Requisition approval',
            'subject_type' => PurchaseRequisition::class,
            'is_active' => true,
            // defaultFor() only finds the default one, and submit() throws
            // rather than approving silently when there is none.
            'is_default' => true,
        ]);

        $workflow->steps()->create([
            'company_id' => $this->company->id,
            'position' => 1,
            'name' => 'Step 1',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
        ]);

        return $workflow->fresh();
    }

    protected function supplier(string $name): Contact
    {
        return Contact::create([
            'company_id' => $this->company->id,
            'type' => 'supplier',
            'name' => $name,
        ]);
    }

    protected function approvedRequisition(): PurchaseRequisition
    {
        $requisition = app(RequisitionService::class)->create([
            'title' => 'Site generator spares',
            'lines' => [
                ['description' => 'Fuel filter', 'quantity' => 10, 'estimated_unit_price' => 15_000],
            ],
        ], $this->owner);

        $instance = app(RequisitionService::class)->submit($requisition, $this->workflow(), $this->owner);
        app(WorkflowEngine::class)->act($instance, $this->buyer, 'approved');

        return $requisition->fresh();
    }

    /** An RFQ with one invited supplier who has quoted. */
    protected function quotedRfq(): Rfq
    {
        $rfq = app(SourcingService::class)->openRfq($this->approvedRequisition(), [], $this->owner);
        $supplier = $this->supplier('Sotrafic Sarl');

        app(SourcingService::class)->invite($rfq, [$supplier->id], $this->owner);
        app(SourcingService::class)->recordQuotation($rfq, $supplier, [
            'lead_time_days' => 7,
            'payment_terms' => '30 days',
            'lines' => [['description' => 'Fuel filter', 'quantity' => 10, 'unit_price' => 14_000]],
        ], $this->owner);

        return $rfq->fresh();
    }

    // ----------------------------------------------------------- requisitions

    public function test_a_requisition_can_be_raised_and_submitted_from_the_screen(): void
    {
        $this->workflow();

        Livewire::actingAs($this->buyer)
            ->test(RequisitionsScreen::class)
            ->call('startRaising')
            ->set('title', 'Site generator spares')
            ->set('lines', [
                ['description' => 'Fuel filter', 'quantity' => '10', 'unit' => 'unit', 'estimatedUnitPrice' => '15000'],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $requisition = PurchaseRequisition::firstOrFail();
        $this->assertSame('draft', $requisition->status);
        $this->assertSame('150000.00', (string) $requisition->estimated_total);

        Livewire::actingAs($this->buyer)
            ->test(RequisitionsScreen::class)
            ->call('submit', $requisition->id)
            ->assertHasNoErrors();

        $this->assertSame('submitted', $requisition->fresh()->status);
    }

    public function test_a_returned_requisition_does_not_read_like_a_rejected_one(): void
    {
        $requisition = app(RequisitionService::class)->create([
            'title' => 'Spares',
            'lines' => [['description' => 'Filter', 'quantity' => 1, 'estimated_unit_price' => 1000]],
        ], $this->owner);

        $requisition->forceFill(['status' => 'returned'])->save();

        Livewire::actingAs($this->owner)
            ->test(RequisitionsScreen::class, ['filter' => 'all', 'viewing' => $requisition->id])
            ->assertSee('Changes were asked for')
            ->assertDontSee('This was refused');

        $requisition->forceFill(['status' => 'rejected'])->save();

        Livewire::actingAs($this->owner)
            ->test(RequisitionsScreen::class, ['filter' => 'all', 'viewing' => $requisition->id])
            ->assertSee('This was refused')
            ->assertDontSee('Changes were asked for');
    }

    /**
     * The rule the whole module is arranged around: a submitted requisition is
     * decided in the approvals inbox, never here.
     */
    public function test_no_approve_control_appears_on_either_screen(): void
    {
        $requisition = $this->approvedRequisition();
        $requisition->forceFill(['status' => 'submitted'])->save();

        $requisitions = Livewire::actingAs($this->owner)
            ->test(RequisitionsScreen::class, ['filter' => 'all', 'viewing' => $requisition->id]);

        foreach (['>Approve<', 'wire:click="approve', 'Approve requisition', 'Reject'] as $control) {
            $requisitions->assertDontSee($control, false);
        }

        $this->assertFalse(method_exists(RequisitionsScreen::class, 'approve'));
        $this->assertFalse(method_exists(RequisitionsScreen::class, 'reject'));
        $this->assertFalse(method_exists(SourcingScreen::class, 'approve'));

        $sourcing = Livewire::actingAs($this->owner)
            ->test(SourcingScreen::class, ['rfqId' => $this->quotedRfq()->id]);

        foreach (['>Approve<', 'wire:click="approve'] as $control) {
            $sourcing->assertDontSee($control, false);
        }
    }

    // --------------------------------------------------------------- sourcing

    public function test_an_uninvited_supplier_is_not_offered_to_quote(): void
    {
        $rfq = app(SourcingService::class)->openRfq($this->approvedRequisition(), [], $this->owner);

        $invited = $this->supplier('Sotrafic Sarl');
        $this->supplier('Never Asked Sarl');

        app(SourcingService::class)->invite($rfq, [$invited->id], $this->owner);

        Livewire::actingAs($this->buyer)
            ->test(SourcingScreen::class, ['rfqId' => $rfq->id])
            ->call('startRecording')
            ->assertSeeInOrder(['Supplier', 'Sotrafic Sarl'])
            // Present in the invite checkboxes, never in the quoting dropdown.
            ->set('quotingSupplierId', Contact::where('name', 'Never Asked Sarl')->value('id'))
            ->call('recordQuotation')
            ->assertHasErrors('quotingSupplierId');

        $this->assertSame(0, $rfq->quotations()->count());
    }

    public function test_awarding_produces_a_draft_purchase_order(): void
    {
        $rfq = $this->quotedRfq();
        $quotation = $rfq->quotations()->firstOrFail();

        Livewire::actingAs($this->owner)
            ->test(SourcingScreen::class, ['rfqId' => $rfq->id])
            ->call('award', $quotation->id)
            ->assertHasNoErrors();

        $order = $rfq->fresh()->purchaseOrder;

        $this->assertNotNull($order);
        $this->assertSame(DocumentStatus::Draft, $order->status);
        // Unnumbered on purpose: the issuer owns the sequence.
        $this->assertNull($order->number);
        $this->assertSame('awarded', $quotation->fresh()->status);
    }

    public function test_an_rfq_can_be_closed_without_an_award_and_the_requisition_is_released(): void
    {
        $rfq = $this->quotedRfq();

        Livewire::actingAs($this->buyer)
            ->test(SourcingScreen::class, ['rfqId' => $rfq->id])
            ->call('closeRfq')
            ->assertHasNoErrors();

        $this->assertSame('closed', $rfq->fresh()->status);
        // The approval still stands; the market can be asked again.
        $this->assertSame('approved', $rfq->fresh()->requisition->status);
    }

    public function test_an_rfq_can_be_cancelled_and_a_decided_one_cannot(): void
    {
        $rfq = $this->quotedRfq();

        Livewire::actingAs($this->buyer)
            ->test(SourcingScreen::class, ['rfqId' => $rfq->id])
            ->call('cancelRfq')
            ->assertHasNoErrors();

        $this->assertSame('cancelled', $rfq->fresh()->status);
        $this->assertSame('approved', $rfq->fresh()->requisition->status);

        // Already decided: the second strike shows the refusal, changes nothing.
        Livewire::actingAs($this->buyer)
            ->test(SourcingScreen::class, ['rfqId' => $rfq->id])
            ->call('closeRfq')
            ->assertHasErrors('rfqState');

        $this->assertSame('cancelled', $rfq->fresh()->status);
    }

    public function test_a_quotation_can_be_shortlisted_and_the_mark_taken_off(): void
    {
        $rfq = $this->quotedRfq();
        $quotation = $rfq->quotations()->firstOrFail();

        $screen = Livewire::actingAs($this->buyer)
            ->test(SourcingScreen::class, ['rfqId' => $rfq->id]);

        $screen->call('shortlist', $quotation->id)->assertHasNoErrors();
        $this->assertSame('shortlisted', $quotation->fresh()->status);

        // A bookmark, not a decision — pressing it again takes it off.
        $screen->call('shortlist', $quotation->id)->assertHasNoErrors();
        $this->assertSame('received', $quotation->fresh()->status);
    }

    public function test_a_withdrawn_quotation_leaves_the_comparison_but_an_awarded_one_cannot_be(): void
    {
        $rfq = $this->quotedRfq();
        $quotation = $rfq->quotations()->firstOrFail();

        Livewire::actingAs($this->buyer)
            ->test(SourcingScreen::class, ['rfqId' => $rfq->id])
            ->call('withdrawQuotation', $quotation->id)
            ->assertHasNoErrors();

        $this->assertSame('withdrawn', $quotation->fresh()->status);
        $this->assertTrue(app(SourcingService::class)->compare($rfq->fresh())->isEmpty());

        // An awarded quotation carries an order and cannot be taken back.
        $quotation->forceFill(['status' => 'awarded'])->save();

        Livewire::actingAs($this->buyer)
            ->test(SourcingScreen::class, ['rfqId' => $rfq->id])
            ->call('withdrawQuotation', $quotation->id)
            ->assertHasErrors('award');

        $this->assertSame('awarded', $quotation->fresh()->status);
    }

    public function test_somebody_without_the_award_ability_cannot_award(): void
    {
        $rfq = $this->quotedRfq();
        $quotation = $rfq->quotations()->firstOrFail();

        $screen = Livewire::actingAs($this->buyer)
            ->test(SourcingScreen::class, ['rfqId' => $rfq->id]);

        // Not even offered...
        $screen->assertDontSee('wire:click="award(', false);

        // ...and refused if asked for directly.
        $screen->call('award', $quotation->id)->assertForbidden();

        $this->assertSame('received', $quotation->fresh()->status);
    }
}
