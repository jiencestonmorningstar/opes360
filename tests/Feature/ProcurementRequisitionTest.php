<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Events\DomainEvent;
use App\Listeners\SyncApprovedRequisitions;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\Role;
use App\Models\SupplierQuotation;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Procurement\Requisitions;
use App\Services\Procurement\Sourcing;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * The half of procurement that happens before a purchase order exists.
 *
 * Somebody asks for something, the business asks suppliers what it would
 * cost, one answer is chosen, and only then is an order raised. The approval
 * in the middle is not implemented here at all — it is the shared workflow
 * engine, and these tests assert that rather than a second one.
 */
class ProcurementRequisitionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $manager;

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
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        $this->manager = User::factory()->create();
        $this->joinCompany($this->company, $this->manager, Role::MANAGER);
        $this->manager->forceFill(['current_company_id' => $this->company->id])->save();

        // Registered here because AppServiceProvider belongs to the integrating
        // agent; the same line is in docs/handoff/3.5-integration.md. Remove
        // this once it is wired up for real.
        Event::listen(DomainEvent::class, SyncApprovedRequisitions::class);
    }

    // ---------------------------------------------------------------- helpers

    protected function requisitions(): Requisitions
    {
        return app(Requisitions::class);
    }

    protected function sourcing(): Sourcing
    {
        return app(Sourcing::class);
    }

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }

    /** @param  array<int, array<string, mixed>>  $steps */
    protected function workflow(array $steps = [[]]): Workflow
    {
        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Requisition approval',
            'subject_type' => PurchaseRequisition::class,
            'is_active' => true,
        ]);

        foreach ($steps as $index => $step) {
            $workflow->steps()->create(array_merge([
                'company_id' => $this->company->id,
                'position' => $index + 1,
                'name' => 'Step '.($index + 1),
                'type' => 'approval',
                'approver_mode' => 'role',
                'approver_role' => Role::MANAGER,
                'quorum' => 'any',
            ], $step));
        }

        return $workflow->fresh();
    }

    protected function supplier(string $name = 'Sotrafic Sarl'): Contact
    {
        return Contact::create([
            'company_id' => $this->company->id,
            'type' => 'supplier',
            'name' => $name,
        ]);
    }

    /** @param  array<int, array<string, mixed>>|null  $lines */
    protected function requisition(?array $lines = null, float $qty = 10): PurchaseRequisition
    {
        return $this->requisitions()->create([
            'title' => 'Site generator spares',
            'needed_by' => now()->addWeek()->toDateString(),
            'justification' => 'The standby generator will not start.',
            'lines' => $lines ?? [
                ['description' => 'Fuel filter', 'quantity' => $qty, 'estimated_unit_price' => 15_000],
                ['description' => 'Oil, 20L drum', 'quantity' => 2, 'estimated_unit_price' => 45_000],
            ],
        ], $this->owner);
    }

    /** A requisition the engine has actually approved. */
    protected function approvedRequisition(float $qty = 10): PurchaseRequisition
    {
        $requisition = $this->requisition(qty: $qty);
        $instance = $this->requisitions()->submit($requisition, $this->workflow(), $this->owner);
        $this->engine()->act($instance, $this->manager, 'approved');

        return $requisition->fresh();
    }

    // ------------------------------------------------------------ requisition

    public function test_a_requisition_totals_its_lines(): void
    {
        $requisition = $this->requisition();

        $this->assertSame('240000.00', (string) $requisition->estimated_total);
        $this->assertCount(2, $requisition->lines);
        $this->assertSame('150000.00', (string) $requisition->lines->first()->estimated_total);
    }

    public function test_a_requisition_starts_as_a_draft_with_a_number(): void
    {
        $requisition = $this->requisition();

        $this->assertSame('draft', $requisition->status);
        $this->assertStringStartsWith('PR-', (string) $requisition->number);
    }

    public function test_requisition_numbers_do_not_repeat(): void
    {
        $numbers = collect([$this->requisition(), $this->requisition(), $this->requisition()])
            ->pluck('number');

        $this->assertCount(3, $numbers->unique());
    }

    public function test_a_requisition_needs_at_least_one_line(): void
    {
        $this->expectException(RuntimeException::class);

        $this->requisitions()->create([
            'title' => 'Nothing at all',
            'needed_by' => now()->toDateString(),
            'lines' => [],
        ], $this->owner);
    }

    // --------------------------------------------------------------- approval

    public function test_submitting_hands_the_requisition_to_the_shared_engine(): void
    {
        $requisition = $this->requisition();

        $instance = $this->requisitions()->submit($requisition, $this->workflow(), $this->owner);

        $this->assertInstanceOf(WorkflowInstance::class, $instance);
        $this->assertSame(PurchaseRequisition::class, $instance->subject_type);
        $this->assertSame($requisition->id, $instance->subject_id);
        $this->assertSame('running', $instance->status);
        $this->assertSame('submitted', $requisition->fresh()->status);
        $this->assertTrue($requisition->fresh()->isAwaitingApproval());
    }

    public function test_the_service_has_no_approval_method_of_its_own(): void
    {
        $methods = collect((new ReflectionClass(Requisitions::class))->getMethods())
            ->pluck('name')
            ->map(fn (string $name) => Str::lower($name));

        // A second way to approve something is how a product ends up with four
        // approval engines and two of them wrong.
        $this->assertFalse($methods->contains('approve'));
        $this->assertFalse($methods->contains('reject'));
    }

    public function test_a_requisition_cannot_be_submitted_twice(): void
    {
        $requisition = $this->requisition();
        $this->requisitions()->submit($requisition, $this->workflow(), $this->owner);

        $this->expectException(RuntimeException::class);

        $this->requisitions()->submit($requisition->fresh(), $this->workflow(), $this->owner);
    }

    public function test_submitting_without_an_approval_path_is_refused(): void
    {
        $requisition = $this->requisition();

        $this->expectException(RuntimeException::class);

        $this->requisitions()->submit($requisition, null, $this->owner);
    }

    public function test_the_engines_yes_marks_the_requisition_approved(): void
    {
        $requisition = $this->approvedRequisition();

        $this->assertTrue($requisition->isApproved());
        $this->assertSame('approved', $requisition->status);
        $this->assertNotNull($requisition->approved_at);
    }

    public function test_the_engines_no_marks_the_requisition_rejected(): void
    {
        $requisition = $this->requisition();
        $instance = $this->requisitions()->submit($requisition, $this->workflow(), $this->owner);

        $this->engine()->act($instance, $this->manager, 'rejected', 'We already hold these.');

        $this->assertSame('rejected', $requisition->fresh()->status);
    }

    public function test_changes_requested_returns_the_requisition_rather_than_killing_it(): void
    {
        $requisition = $this->requisition();
        $instance = $this->requisitions()->submit($requisition, $this->workflow(), $this->owner);

        $this->engine()->act($instance, $this->manager, 'changes_requested', 'Quote three suppliers first.');

        // "No" and "not yet" are different answers; a returned requisition is
        // still resubmittable, a rejected one is not.
        $this->assertSame('returned', $requisition->fresh()->status);
    }

    public function test_a_large_requisition_picks_up_the_extra_step_by_configuration(): void
    {
        $workflow = $this->workflow([
            ['name' => 'Manager'],
            [
                'name' => 'Director',
                'approver_mode' => 'owner',
                'conditions' => [
                    ['field' => 'estimated_total', 'operator' => '>=', 'value' => 10_000_000],
                ],
            ],
        ]);

        // 1,000 filters at 15,000 = 15,000,000, plus the oil: over the limit.
        $requisition = $this->requisition(qty: 1_000);
        $instance = $this->requisitions()->submit($requisition, $workflow, $this->owner);

        $this->engine()->act($instance, $this->manager, 'approved');

        // Still running: the second step applied, so the manager alone is not
        // enough. No code in this module says so — the condition is data.
        $this->assertSame('running', $instance->fresh()->status);
        $this->assertSame('submitted', $requisition->fresh()->status);

        $this->engine()->act($instance->fresh(), $this->owner, 'approved');

        $this->assertSame('approved', $requisition->fresh()->status);
    }

    // -------------------------------------------------------------------- RFQ

    public function test_an_unapproved_requisition_cannot_go_out_to_suppliers(): void
    {
        $requisition = $this->requisition();

        $this->expectException(RuntimeException::class);

        $this->sourcing()->openRfq($requisition, [], $this->owner);
    }

    public function test_an_rfq_copies_the_approved_requisitions_lines(): void
    {
        $requisition = $this->approvedRequisition();

        $rfq = $this->sourcing()->openRfq($requisition, ['closes_on' => now()->addDays(7)->toDateString()], $this->owner);

        $this->assertInstanceOf(Rfq::class, $rfq);
        $this->assertSame('draft', $rfq->status);
        $this->assertStringStartsWith('RFQ-', (string) $rfq->number);
        $this->assertCount(2, $rfq->lines);
        $this->assertSame('Fuel filter', $rfq->lines->first()->description);
        $this->assertSame($requisition->lines->first()->id, $rfq->lines->first()->purchase_requisition_line_id);
        $this->assertSame('sourcing', $requisition->fresh()->status);
    }

    public function test_inviting_the_same_supplier_twice_does_not_duplicate_it(): void
    {
        $rfq = $this->sourcing()->openRfq($this->approvedRequisition(), [], $this->owner);
        $supplier = $this->supplier();

        $this->sourcing()->invite($rfq, [$supplier->id, $supplier->id], $this->owner);
        $this->sourcing()->invite($rfq, [$supplier->id], $this->owner);

        $this->assertCount(1, $rfq->fresh()->invitations);
        $this->assertSame('sent', $rfq->fresh()->status);
    }

    public function test_a_supplier_from_another_business_cannot_be_invited(): void
    {
        $rfq = $this->sourcing()->openRfq($this->approvedRequisition(), [], $this->owner);

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $stranger = Contact::create(['company_id' => $other->id, 'type' => 'supplier', 'name' => 'Stranger']);

        $this->expectException(RuntimeException::class);

        $this->sourcing()->invite($rfq, [$stranger->id], $this->owner);
    }

    // ------------------------------------------------------------- quotations

    public function test_a_quotation_totals_its_lines(): void
    {
        $rfq = $this->sourcing()->openRfq($this->approvedRequisition(), [], $this->owner);
        $supplier = $this->supplier();
        $this->sourcing()->invite($rfq, [$supplier->id], $this->owner);

        $quotation = $this->sourcing()->recordQuotation($rfq, $supplier, [
            'reference' => 'DEV-2026-88',
            'lead_time_days' => 5,
            'lines' => [
                ['description' => 'Fuel filter', 'quantity' => 10, 'unit_price' => 14_000],
                ['description' => 'Oil, 20L drum', 'quantity' => 2, 'unit_price' => 44_000],
            ],
        ], $this->owner);

        $this->assertInstanceOf(SupplierQuotation::class, $quotation);
        $this->assertSame('228000.00', (string) $quotation->total);
        $this->assertSame('received', $quotation->status);
        $this->assertNotNull($rfq->fresh()->responded_at);
        $this->assertNotNull($rfq->invitations()->where('supplier_id', $supplier->id)->first()->responded_at);
    }

    public function test_a_supplier_who_was_never_asked_cannot_quote(): void
    {
        $rfq = $this->sourcing()->openRfq($this->approvedRequisition(), [], $this->owner);

        $this->expectException(RuntimeException::class);

        $this->sourcing()->recordQuotation($rfq, $this->supplier(), [
            'lines' => [['description' => 'Fuel filter', 'quantity' => 1, 'unit_price' => 1]],
        ], $this->owner);
    }

    public function test_a_quotation_needs_at_least_one_line(): void
    {
        $rfq = $this->sourcing()->openRfq($this->approvedRequisition(), [], $this->owner);
        $supplier = $this->supplier();
        $this->sourcing()->invite($rfq, [$supplier->id], $this->owner);

        $this->expectException(RuntimeException::class);

        $this->sourcing()->recordQuotation($rfq, $supplier, ['lines' => []], $this->owner);
    }

    public function test_the_comparison_puts_the_cheapest_first(): void
    {
        $rfq = $this->sourcing()->openRfq($this->approvedRequisition(), [], $this->owner);
        $dear = $this->supplier('Dear Sarl');
        $cheap = $this->supplier('Cheap Sarl');
        $this->sourcing()->invite($rfq, [$dear->id, $cheap->id], $this->owner);

        $this->sourcing()->recordQuotation($rfq, $dear, [
            'lines' => [['description' => 'Fuel filter', 'quantity' => 10, 'unit_price' => 20_000]],
        ], $this->owner);
        $this->sourcing()->recordQuotation($rfq, $cheap, [
            'lines' => [['description' => 'Fuel filter', 'quantity' => 10, 'unit_price' => 12_000]],
        ], $this->owner);

        $ranked = $this->sourcing()->compare($rfq->fresh());

        $this->assertSame($cheap->id, $ranked->first()->supplier_id);
        $this->assertCount(2, $ranked);
    }

    // ------------------------------------------------------------------ award

    public function test_awarding_a_quotation_raises_a_draft_purchase_order(): void
    {
        $requisition = $this->approvedRequisition();
        $rfq = $this->sourcing()->openRfq($requisition, [], $this->owner);
        $supplier = $this->supplier();
        $this->sourcing()->invite($rfq, [$supplier->id], $this->owner);

        $quotation = $this->sourcing()->recordQuotation($rfq, $supplier, [
            'lines' => [
                ['description' => 'Fuel filter', 'quantity' => 10, 'unit_price' => 14_000],
                ['description' => 'Oil, 20L drum', 'quantity' => 2, 'unit_price' => 44_000],
            ],
        ], $this->owner);

        $order = $this->sourcing()->award($quotation, $this->owner);

        $this->assertInstanceOf(Document::class, $order);
        $this->assertSame(DocumentType::PurchaseOrder, $order->type);
        $this->assertSame($supplier->id, $order->contact_id);
        $this->assertSame('draft', $order->status->value);
        $this->assertCount(2, $order->lines);
        $this->assertSame('228000.00', (string) $order->total);

        $this->assertSame('awarded', $quotation->fresh()->status);
        $this->assertSame('awarded', $rfq->fresh()->status);
        $this->assertSame('ordered', $requisition->fresh()->status);
        $this->assertSame($order->id, $rfq->fresh()->purchase_order_id);
    }

    public function test_awarding_turns_the_other_quotations_down(): void
    {
        $rfq = $this->sourcing()->openRfq($this->approvedRequisition(), [], $this->owner);
        $winner = $this->supplier('Winner Sarl');
        $loser = $this->supplier('Loser Sarl');
        $this->sourcing()->invite($rfq, [$winner->id, $loser->id], $this->owner);

        $good = $this->sourcing()->recordQuotation($rfq, $winner, [
            'lines' => [['description' => 'Fuel filter', 'quantity' => 10, 'unit_price' => 12_000]],
        ], $this->owner);
        $bad = $this->sourcing()->recordQuotation($rfq, $loser, [
            'lines' => [['description' => 'Fuel filter', 'quantity' => 10, 'unit_price' => 20_000]],
        ], $this->owner);

        $this->sourcing()->award($good, $this->owner);

        $this->assertSame('rejected', $bad->fresh()->status);
    }

    public function test_an_rfq_cannot_be_awarded_twice(): void
    {
        $rfq = $this->sourcing()->openRfq($this->approvedRequisition(), [], $this->owner);
        $supplier = $this->supplier();
        $this->sourcing()->invite($rfq, [$supplier->id], $this->owner);

        $quotation = $this->sourcing()->recordQuotation($rfq, $supplier, [
            'lines' => [['description' => 'Fuel filter', 'quantity' => 10, 'unit_price' => 12_000]],
        ], $this->owner);

        $this->sourcing()->award($quotation, $this->owner);

        $this->expectException(RuntimeException::class);

        $this->sourcing()->award($quotation->fresh(), $this->owner);
    }

    // ----------------------------------------------------- straight to an order

    public function test_an_approved_requisition_can_become_an_order_without_an_rfq(): void
    {
        $requisition = $this->approvedRequisition();
        $supplier = $this->supplier();

        $order = $this->sourcing()->orderDirect($requisition, $supplier, $this->owner);

        $this->assertSame(DocumentType::PurchaseOrder, $order->type);
        $this->assertSame('240000.00', (string) $order->total);
        $this->assertSame('ordered', $requisition->fresh()->status);
        $this->assertSame($order->id, $requisition->fresh()->purchase_order_id);
    }

    public function test_an_unapproved_requisition_cannot_become_an_order(): void
    {
        $requisition = $this->requisition();

        $this->expectException(RuntimeException::class);

        $this->sourcing()->orderDirect($requisition, $this->supplier(), $this->owner);
    }

    public function test_a_rejected_requisition_cannot_become_an_order(): void
    {
        $requisition = $this->requisition();
        $instance = $this->requisitions()->submit($requisition, $this->workflow(), $this->owner);
        $this->engine()->act($instance, $this->manager, 'rejected');

        $this->expectException(RuntimeException::class);

        $this->sourcing()->orderDirect($requisition->fresh(), $this->supplier(), $this->owner);
    }

    public function test_a_requisition_already_ordered_cannot_be_ordered_again(): void
    {
        $requisition = $this->approvedRequisition();
        $this->sourcing()->orderDirect($requisition, $this->supplier(), $this->owner);

        $this->expectException(RuntimeException::class);

        $this->sourcing()->orderDirect($requisition->fresh(), $this->supplier('Second Sarl'), $this->owner);
    }
}
