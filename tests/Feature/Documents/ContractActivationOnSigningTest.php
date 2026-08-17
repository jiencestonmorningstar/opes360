<?php

namespace Tests\Feature\Documents;

use App\Models\Contact;
use App\Models\Contract;
use App\Models\Role;
use App\Models\Workflow;
use App\Services\Contracts\ContractLifecycle;
use App\Services\Documents\DocumentLinker;
use App\Services\Documents\DocumentSignatureRequests;

/**
 * CLM's "ERP Binding" (master spec §8.1): a contract goes live the moment
 * its paper is fully executed, without a second manual "activate" click.
 * `ActivateApprovedContracts` already does the internal-approval half of
 * this; `ActivateContractsOnDocumentSigned` (tested here) does the
 * e-signature half.
 */
class ContractActivationOnSigningTest extends DocumentsTestCase
{
    protected function contract(array $overrides = []): Contract
    {
        $counterparty = Contact::create(['name' => 'Sonel Facilities', 'type' => 'supplier']);

        return app(ContractLifecycle::class)->raise(array_merge([
            'title' => 'Office cleaning',
            'contact_id' => $counterparty->id,
            'direction' => 'inbound',
            'type' => 'service',
            'starts_on' => now()->toDateString(),
        ], $overrides), $this->owner);
    }

    protected function fullySign(\App\Models\BusinessDocument $paper): void
    {
        $requests = app(DocumentSignatureRequests::class);
        $signature = $requests->request($paper, [['name' => 'Counterparty', 'email' => 'them@example.com']])->first();

        $requests->sign($signature);
    }

    public function test_signing_the_linked_paper_activates_a_draft_contract(): void
    {
        $contract = $this->contract();
        $paper = $this->document();
        app(DocumentLinker::class)->attach($paper, $contract, 'about', $this->owner);

        $this->fullySign($paper->fresh());

        $this->assertSame('active', $contract->fresh()->status);
    }

    public function test_signing_an_unlinked_paper_does_nothing_to_any_contract(): void
    {
        $contract = $this->contract();
        $paper = $this->document(); // never linked

        $this->fullySign($paper->fresh());

        $this->assertSame('draft', $contract->fresh()->status);
    }

    public function test_signing_a_papers_linked_document_does_not_reactivate_a_terminated_contract(): void
    {
        $contract = $this->contract();
        app(ContractLifecycle::class)->activate($contract, $this->owner);
        app(ContractLifecycle::class)->terminate($contract->fresh(), [], $this->owner);

        $paper = $this->document();
        app(DocumentLinker::class)->attach($paper, $contract, 'about', $this->owner);

        $this->fullySign($paper->fresh());

        $this->assertSame('terminated', $contract->fresh()->status);
    }

    public function test_signing_does_not_race_an_internal_approval_already_in_progress(): void
    {
        $this->memberAt(Role::MANAGER);
        $contract = $this->contract();
        $paper = $this->document();
        app(DocumentLinker::class)->attach($paper, $contract, 'about', $this->owner);

        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Contract approval',
            'subject_type' => Contract::class,
            'is_active' => true,
            'is_default' => true,
        ]);
        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Manager',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
        ]);
        app(ContractLifecycle::class)->submit($contract, $this->owner, $workflow->fresh());

        $this->fullySign($paper->fresh());

        // Left alone rather than force-activated or throwing — the internal
        // decision process is still live and gets to reach its own verdict.
        $this->assertTrue($contract->fresh()->isAwaitingApproval());
        $this->assertSame('draft', $contract->fresh()->status);
    }
}
