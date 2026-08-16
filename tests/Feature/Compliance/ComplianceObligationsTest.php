<?php

namespace Tests\Feature\Compliance;

use App\Events\DomainEvent;
use App\Listeners\CompleteApprovedComplianceFilings;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\ComplianceFiling;
use App\Models\ComplianceObligation;
use App\Models\Role;
use App\Services\Compliance\ComplianceRegister;
use App\Services\Documents\DocumentLinker;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The statutory duties a business must not miss.
 *
 * The point of the register is that nothing recurring lives in one person's
 * head. These tests cover the two questions that actually decide whether it
 * works: does completing one raise the next on the right date, and does the
 * evidence live with the documents module rather than in a second store.
 */
class ComplianceObligationsTest extends ComplianceTestCase
{
    protected function register(): ComplianceRegister
    {
        return app(ComplianceRegister::class);
    }

    // ────────────────────────────────────────────────── raising work ──

    /** An occurrence carries the date it was due, and keeps it afterwards. */
    public function test_raising_a_filing_takes_the_obligations_due_date(): void
    {
        $obligation = $this->obligation();

        $filing = $this->register()->raise($obligation);

        $this->assertSame($obligation->next_due_on->toDateString(), $filing->due_on->toDateString());
        $this->assertSame('draft', $filing->status);
        $this->assertFalse($filing->isDone());
    }

    /**
     * Two people opening the same return must not produce two returns. A
     * duplicate here is not a tidiness problem: it is a second declaration
     * against the same period, and the register would then disagree with
     * itself about whether the period was filed.
     */
    public function test_raising_the_same_occurrence_twice_returns_the_one_already_open(): void
    {
        $obligation = $this->obligation();

        $first = $this->register()->raise($obligation);
        $second = $this->register()->raise($obligation);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ComplianceFiling::count());
    }

    // ───────────────────────────────────────────── the next occurrence ──

    /**
     * The whole reason this is not AssetServicing.
     *
     * A quarterly TVA return filed six weeks late does not move the next
     * quarter. The authority sets the calendar, not the business's
     * punctuality — counting from the completion date would quietly push
     * every future deadline out by however late the last one was, and after
     * four late quarters the register would be a year adrift from the law.
     */
    public function test_a_statutory_deadline_is_counted_from_when_it_was_due(): void
    {
        $due = now()->subWeeks(6)->startOfDay();
        $obligation = $this->obligation(['next_due_on' => $due->toDateString()]);

        $filing = $this->register()->raise($obligation);
        $this->register()->complete($filing, now());

        $this->assertSame(
            $due->copy()->addMonths(3)->toDateString(),
            $obligation->fresh()->next_due_on->toDateString(),
            'Three months from the deadline, not from the day somebody got round to it.',
        );
    }

    /**
     * The opposite case, and the reason the basis is a column rather than a
     * rule. A licence renewed in March runs for a year from March — the new
     * certificate says so. Counting that one from the old expiry date would
     * have the business renewing before it needed to, every year, forever.
     */
    public function test_an_anniversary_renewal_is_counted_from_when_it_was_done(): void
    {
        $obligation = $this->obligation([
            'name' => 'Trade licence renewal',
            'category' => 'licence',
            'interval_months' => 12,
            'schedule_basis' => 'completion',
            'next_due_on' => now()->subWeeks(6)->toDateString(),
        ]);

        $done = now()->startOfDay();
        $this->register()->complete($this->register()->raise($obligation), $done);

        $this->assertSame(
            $done->copy()->addMonths(12)->toDateString(),
            $obligation->fresh()->next_due_on->toDateString(),
            'A year from the renewal, because that is what the new licence says.',
        );
    }

    /** A one-off duty does not quietly become a standing commitment. */
    public function test_a_one_off_obligation_does_not_repeat(): void
    {
        $obligation = $this->obligation(['interval_months' => null]);

        $this->register()->complete($this->register()->raise($obligation), now());

        $fresh = $obligation->fresh();

        $this->assertNull($fresh->next_due_on, 'Nothing is outstanding.');
        $this->assertFalse($fresh->is_active, 'And it is done with.');
    }

    /** Filing the same period twice would tell the register a lie about the next one. */
    public function test_a_completed_filing_cannot_be_completed_again(): void
    {
        $obligation = $this->obligation();
        $filing = $this->register()->raise($obligation);

        $this->register()->complete($filing, now());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already/');

        $this->register()->complete($filing->fresh(), now());
    }

    /** The history is what proves compliance; the obligation only says what is next. */
    public function test_the_filing_history_survives_the_obligation_rolling_forward(): void
    {
        $obligation = $this->obligation(['next_due_on' => now()->subMonths(3)->toDateString()]);

        $first = $this->register()->raise($obligation);
        $this->register()->complete($first, now()->subMonths(3), ['reference' => 'DGI-Q1']);

        $second = $this->register()->raise($obligation->fresh());
        $this->register()->complete($second, now(), ['reference' => 'DGI-Q2']);

        $this->assertSame(2, $obligation->filings()->count());
        $this->assertSame('DGI-Q1', $first->fresh()->reference);
    }

    // ───────────────────────────────────────────────────── evidence ──

    /**
     * Evidence IS a document. There is no second document store here, and
     * this test exists to keep it that way: the proof of filing is reachable
     * through the documents module's own linker, using its own relation
     * table, exactly as an invoice's paperwork is.
     */
    public function test_evidence_is_an_ordinary_business_document(): void
    {
        $obligation = $this->obligation();
        $filing = $this->register()->raise($obligation);

        $receipt = BusinessDocument::create([
            'company_id' => $this->company->id,
            'template' => 'letter',
            'title' => 'DGI acknowledgement Q2',
            'kind' => 'certificate',
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);

        $this->register()->attachEvidence($filing, $receipt, $this->owner);

        $found = app(DocumentLinker::class)->documentsFor($filing->fresh());

        $this->assertCount(1, $found);
        $this->assertSame($receipt->id, $found->first()->id);
        $this->assertSame(1, $this->register()->evidenceCount($filing->fresh()));
    }

    // ────────────────────────────────────────────────────── sign-off ──

    /**
     * Sign-off runs through the one approval engine, and the filing is only
     * complete once the engine says so.
     *
     * The listener is registered here rather than faked, because a faked
     * event proves the emission and nothing else. What has to be true is that
     * the obligation actually moved.
     */
    public function test_a_filing_needing_sign_off_completes_only_when_approved(): void
    {
        Event::listen(DomainEvent::class, CompleteApprovedComplianceFilings::class);

        $manager = $this->memberAt(Role::MANAGER);
        $this->signOffWorkflow();

        $due = now()->addMonth()->startOfDay();
        $obligation = $this->obligation([
            'requires_approval' => true,
            'next_due_on' => $due->toDateString(),
        ]);

        $filing = $this->register()->submit(
            $this->register()->raise($obligation),
            $this->owner,
            ['reference' => 'DGI-Q3'],
        );

        $this->assertSame('submitted', $filing->status, 'Waiting on somebody.');
        $this->assertTrue($filing->isAwaitingApproval());
        $this->assertSame(
            $due->toDateString(),
            $obligation->fresh()->next_due_on->toDateString(),
            'Nothing has rolled forward on the strength of a request.',
        );

        app(WorkflowEngine::class)->act($filing->approval(), $manager, 'approved');

        $this->assertSame('completed', $filing->fresh()->status);
        $this->assertSame(
            $due->copy()->addMonths(3)->toDateString(),
            $obligation->fresh()->next_due_on->toDateString(),
            'And only now does the next quarter appear.',
        );
    }

    /** A refused filing leaves the deadline exactly where it was. */
    public function test_a_rejected_filing_leaves_the_obligation_outstanding(): void
    {
        Event::listen(DomainEvent::class, CompleteApprovedComplianceFilings::class);

        $manager = $this->memberAt(Role::MANAGER);
        $this->signOffWorkflow();

        $due = now()->addMonth()->startOfDay();
        $obligation = $this->obligation(['requires_approval' => true, 'next_due_on' => $due->toDateString()]);

        $filing = $this->register()->submit($this->register()->raise($obligation), $this->owner);

        app(WorkflowEngine::class)->act($filing->approval(), $manager, 'rejected');

        $this->assertSame('rejected', $filing->fresh()->status);
        $this->assertSame($due->toDateString(), $obligation->fresh()->next_due_on->toDateString());
    }

    /**
     * "No" and "not yet" stay different answers here too. A filing sent back
     * for a missing attachment returns to draft and can be submitted again;
     * it is not a refusal to file.
     */
    public function test_a_filing_sent_back_returns_to_draft(): void
    {
        Event::listen(DomainEvent::class, CompleteApprovedComplianceFilings::class);

        $manager = $this->memberAt(Role::MANAGER);
        $this->signOffWorkflow();

        $filing = $this->register()->submit(
            $this->register()->raise($this->obligation(['requires_approval' => true])),
            $this->owner,
        );

        app(WorkflowEngine::class)->act($filing->approval(), $manager, 'changes_requested');

        $this->assertSame('draft', $filing->fresh()->status);
    }

    /**
     * An obligation that asks for sign-off when nobody has defined a path
     * must not quietly file itself. Missing configuration is a refusal, not
     * an approval — the opposite choice would mean the strictest obligations
     * were the ones that skipped review.
     */
    public function test_sign_off_with_no_workflow_defined_is_refused_rather_than_waved_through(): void
    {
        $filing = $this->register()->raise($this->obligation(['requires_approval' => true]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/sign-off/');

        $this->register()->submit($filing, $this->owner);
    }

    /** With no sign-off asked for, submitting is filing. */
    public function test_a_filing_needing_no_sign_off_completes_on_submission(): void
    {
        $obligation = $this->obligation();

        $filing = $this->register()->submit($this->register()->raise($obligation), $this->owner);

        $this->assertSame('completed', $filing->status);
        $this->assertNotNull($filing->completed_on);
    }

    // ─────────────────────────────────────────────────────── tenancy ──

    public function test_another_business_cannot_see_these_obligations(): void
    {
        $this->obligation();

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, ComplianceObligation::count());
        $this->assertSame(0, ComplianceFiling::count());
    }
}
