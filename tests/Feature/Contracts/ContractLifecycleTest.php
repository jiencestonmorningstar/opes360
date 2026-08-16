<?php

namespace Tests\Feature\Contracts;

use App\Models\BusinessDocument;
use App\Models\Contract;
use App\Models\ContractObligation;
use App\Models\Role;
use App\Services\Documents\DocumentLinker;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Raising a contract, getting it agreed, and the two ways it ends.
 *
 * The approval is deliberately absent from the service under test. If a
 * `ContractLifecycle::approve()` ever appears, this suite is where it should
 * be caught: a second approval path is the duplication the brief forbids.
 */
class ContractLifecycleTest extends ContractsTestCase
{
    public function test_a_raised_contract_starts_as_a_draft(): void
    {
        $contract = $this->contract();

        // Explicitly asserted rather than assumed: a DB column default is not
        // written back onto the model Eloquent just created, so a status left
        // to the database reads as null for the rest of the request.
        $this->assertSame('draft', $contract->status);
        $this->assertFalse($contract->isActive());
        $this->assertSame($this->supplier->id, $contract->counterparty->id);
    }

    public function test_a_contract_cannot_end_before_it_starts(): void
    {
        $this->expectException(RuntimeException::class);

        $this->contract([
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * The deadline nobody remembers, worked out once and stored.
     *
     * Derived at read time it would be correct and invisible; stored, it can
     * be indexed, sorted and alerted on, which is the only form in which it
     * ever stops a contract renewing by accident.
     */
    public function test_the_notice_deadline_is_worked_out_from_the_end_date(): void
    {
        $contract = $this->contract([
            'ends_on' => '2027-06-30',
            'notice_period_days' => 90,
        ]);

        $this->assertSame('2027-04-01', $contract->notice_by->toDateString());
    }

    public function test_moving_the_end_date_moves_the_notice_deadline(): void
    {
        $contract = $this->contract(['ends_on' => '2027-06-30', 'notice_period_days' => 90]);

        $contract->update(['ends_on' => '2027-12-31']);

        $this->assertSame('2027-10-02', $contract->fresh()->notice_by->toDateString());
    }

    public function test_a_contract_with_no_notice_period_has_no_deadline(): void
    {
        $contract = $this->contract(['renewal_type' => 'none', 'notice_period_days' => null]);

        $this->assertNull($contract->notice_by);
    }

    /**
     * The trap this module exists to close, refused at the point of entry:
     * a contract that renews itself and names no notice period produces no
     * date anybody could be warned about, so it rolls over in silence forever.
     */
    public function test_an_auto_renewing_contract_must_name_a_notice_period(): void
    {
        $this->expectException(RuntimeException::class);

        $this->contract(['renewal_type' => 'auto', 'notice_period_days' => null]);
    }

    public function test_an_open_ended_contract_has_no_notice_deadline(): void
    {
        $contract = $this->contract(['ends_on' => null, 'notice_period_days' => 30]);

        $this->assertNull($contract->notice_by);
    }

    // ------------------------------------------------------------- approval

    public function test_a_contract_is_submitted_through_the_shared_engine(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->workflow();
        $contract = $this->contract();

        $instance = $this->service()->submit($contract, $this->owner);

        $this->assertTrue($contract->fresh()->isAwaitingApproval());
        $this->assertSame([$manager->id], $instance->assignments->pluck('user_id')->all());
    }

    public function test_the_service_offers_no_approve_method(): void
    {
        $this->assertFalse(method_exists($this->service(), 'approve'));
        $this->assertFalse(method_exists($this->service(), 'reject'));
    }

    /**
     * Proven by its side effect rather than with Event::fake(), which would
     * switch off the very listener under test.
     */
    public function test_approval_activates_the_contract(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $contract = $this->contract();
        $instance = $this->engine()->start($contract, $this->workflow(), $this->owner);

        $this->engine()->act($instance, $manager, 'approved');

        $this->assertSame('active', $contract->fresh()->status);
    }

    public function test_a_rejected_contract_stays_a_draft(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $contract = $this->contract();
        $instance = $this->engine()->start($contract, $this->workflow(), $this->owner);

        $this->engine()->act($instance, $manager, 'rejected');

        $this->assertSame('draft', $contract->fresh()->status);
    }

    public function test_a_contract_awaiting_approval_cannot_be_activated_behind_the_engines_back(): void
    {
        $this->memberAt(Role::MANAGER);
        $contract = $this->contract();
        $this->engine()->start($contract, $this->workflow(), $this->owner);

        $this->expectException(RuntimeException::class);

        $this->service()->activate($contract->fresh(), $this->owner);
    }

    public function test_a_contract_that_needed_no_approval_can_be_activated_directly(): void
    {
        $contract = $this->contract();

        $this->service()->activate($contract, $this->owner);

        $this->assertTrue($contract->fresh()->isActive());
    }

    // -------------------------------------------------------------- renewal

    public function test_renewing_extends_the_end_date_and_keeps_the_history(): void
    {
        $contract = $this->contract(['ends_on' => '2027-06-30', 'notice_period_days' => 90]);
        $this->service()->activate($contract, $this->owner);

        $renewal = $this->service()->renew($contract->fresh(), [
            'new_ends_on' => '2028-06-30',
            'method' => 'negotiated',
            'new_value' => 1_500_000,
        ], $this->owner);

        $contract = $contract->fresh();

        $this->assertSame('2028-06-30', $contract->ends_on->toDateString());
        $this->assertSame('2028-04-01', $contract->notice_by->toDateString());
        $this->assertSame('1500000.00', $contract->value);
        $this->assertSame('2027-06-30', $renewal->previous_ends_on->toDateString());
        $this->assertSame(1, $contract->renewals()->count());
    }

    /**
     * A renewal with no explicit end date takes the agreed renewal term, which
     * is the whole reason the term is recorded on the contract.
     */
    public function test_a_renewal_defaults_to_the_agreed_term(): void
    {
        $contract = $this->contract(['ends_on' => '2027-06-30', 'renewal_term_months' => 12]);
        $this->service()->activate($contract, $this->owner);

        $this->service()->renew($contract->fresh(), [], $this->owner);

        $this->assertSame('2028-06-30', $contract->fresh()->ends_on->toDateString());
    }

    public function test_a_terminated_contract_cannot_be_renewed(): void
    {
        $contract = $this->contract();
        $this->service()->activate($contract, $this->owner);
        $this->service()->terminate($contract->fresh(), ['reason' => 'Poor service'], $this->owner);

        $this->expectException(RuntimeException::class);

        $this->service()->renew($contract->fresh(), ['new_ends_on' => '2030-01-01'], $this->owner);
    }

    public function test_a_contract_with_no_end_date_cannot_be_renewed(): void
    {
        $contract = $this->contract(['ends_on' => null]);
        $this->service()->activate($contract, $this->owner);

        $this->expectException(RuntimeException::class);

        $this->service()->renew($contract->fresh(), [], $this->owner);
    }

    // ------------------------------------------------------------ terminate

    public function test_terminating_records_when_and_why(): void
    {
        $contract = $this->contract();
        $this->service()->activate($contract, $this->owner);

        $this->service()->terminate($contract->fresh(), [
            'reason' => 'Repeated failures to attend',
            'on' => now()->addDays(30)->toDateString(),
        ], $this->owner);

        $contract = $contract->fresh();

        $this->assertSame('terminated', $contract->status);
        $this->assertSame('Repeated failures to attend', $contract->termination_reason);
        $this->assertSame(now()->addDays(30)->toDateString(), $contract->terminated_on->toDateString());
        $this->assertSame($this->owner->id, $contract->terminated_by);
    }

    public function test_a_draft_contract_cannot_be_terminated(): void
    {
        $this->expectException(RuntimeException::class);

        $this->service()->terminate($this->contract(), ['reason' => 'Never signed'], $this->owner);
    }

    // ------------------------------------------------------------- the paper

    /**
     * The signed paper is a BusinessDocument, reached through the existing
     * relation table. A second document store here would be the exact
     * duplication the brief forbids.
     */
    public function test_the_signed_paper_is_an_ordinary_business_document(): void
    {
        $contract = $this->contract();

        $paper = BusinessDocument::create([
            'company_id' => $this->company->id,
            'template' => 'letter',
            'title' => 'Office cleaning agreement',
            'kind' => 'contract',
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);

        app(DocumentLinker::class)->attach($paper, $contract, 'about', $this->owner);

        $this->assertTrue($contract->papers()->pluck('id')->contains($paper->id));
    }

    public function test_contracts_have_no_numbering_of_their_own(): void
    {
        $this->assertFalse(
            Schema::hasColumn('contracts', 'number'),
            'A contract must take its reference from its BusinessDocument, not from a second numbering scheme.'
        );
    }

    // --------------------------------------------------------- obligations

    public function test_an_obligation_records_which_side_owes_it(): void
    {
        $contract = $this->contract();

        $obligation = $this->service()->addObligation($contract, [
            'owed_by' => 'them',
            'title' => 'Monthly deep clean',
            'due_on' => now()->addDays(10)->toDateString(),
        ], $this->owner);

        $this->assertInstanceOf(ContractObligation::class, $obligation);
        $this->assertFalse($obligation->isDone());
        $this->assertSame($this->company->id, $obligation->company_id);
    }

    public function test_completing_an_obligation_records_who_and_when(): void
    {
        $contract = $this->contract();
        $obligation = $this->service()->addObligation($contract, [
            'owed_by' => 'us',
            'title' => 'Pay the quarterly fee',
            'due_on' => now()->subDays(3)->toDateString(),
        ], $this->owner);

        $this->service()->completeObligation($obligation, $this->owner);

        $obligation = $obligation->fresh();

        $this->assertTrue($obligation->isDone());
        $this->assertSame($this->owner->id, $obligation->completed_by);
        $this->assertNotNull($obligation->completed_on);
    }

    public function test_obligations_are_listed_against_their_own_contract_only(): void
    {
        $first = $this->contract();
        $second = $this->contract(['title' => 'Security guarding']);

        $this->service()->addObligation($first, ['owed_by' => 'them', 'title' => 'Deep clean'], $this->owner);
        $this->service()->addObligation($second, ['owed_by' => 'them', 'title' => 'Night patrol'], $this->owner);

        $this->assertSame(['Deep clean'], $first->obligations()->pluck('title')->all());
    }
}
