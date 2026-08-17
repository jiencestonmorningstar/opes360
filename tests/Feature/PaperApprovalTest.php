<?php

namespace Tests\Feature;

use App\Livewire\Papers\Show;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Services\DocumentComposer;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * A draft on the company's paper can be put to the approval engine.
 *
 * The listener (TranslateDocumentWorkflowEvents) and the workflow screen entry
 * existed from the start; what was missing was any way for a person to submit
 * one — the flows audit's F2.5/F3.3.
 */
class PaperApprovalTest extends TestCase
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
    }

    protected function draft(): BusinessDocument
    {
        return BusinessDocument::create([
            'company_id' => $this->company->id,
            'template' => 'letter',
            'title' => 'Recommendation letter',
            'body' => 'To whom it may concern.',
            'status' => 'draft',
            'created_by' => $this->owner->id,
            'owner_id' => $this->owner->id,
        ]);
    }

    protected function approvalPath(): Workflow
    {
        $workflow = Workflow::create([
            'name' => 'Document approvals',
            'subject_type' => BusinessDocument::class,
            'is_active' => true,
            'is_default' => true,
        ]);

        $workflow->steps()->create([
            'position' => 1,
            'name' => 'Owner approves',
            'type' => 'approval',
            'approver_mode' => 'owner',
            'quorum' => 'any',
        ]);

        return $workflow->fresh();
    }

    public function test_a_draft_can_be_sent_for_approval_from_its_screen(): void
    {
        $this->approvalPath();
        $paper = $this->draft();

        $this->actingAs($this->owner);

        Livewire::test(Show::class, ['paper' => $paper])
            ->call('submitForApproval');

        $paper->refresh();
        $this->assertTrue($paper->isAwaitingApproval());
    }

    public function test_a_document_awaiting_approval_cannot_be_issued_behind_the_approvers_back(): void
    {
        $this->approvalPath();
        $paper = $this->draft();

        app(DocumentComposer::class)->submitForApproval($paper, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('with somebody for approval');

        app(DocumentComposer::class)->issue($paper->fresh(), $this->owner);
    }

    public function test_once_approved_the_document_issues_normally(): void
    {
        $this->approvalPath();
        $paper = $this->draft();

        $instance = app(DocumentComposer::class)->submitForApproval($paper, $this->owner);
        app(WorkflowEngine::class)->act($instance, $this->owner, 'approved');

        $issued = app(DocumentComposer::class)->issue($paper->fresh(), $this->owner);

        $this->assertTrue($issued->isIssued());
    }

    public function test_with_no_path_defined_the_submit_refuses_with_a_reason(): void
    {
        $paper = $this->draft();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No approval workflow');

        app(DocumentComposer::class)->submitForApproval($paper, $this->owner);
    }

    public function test_an_issued_document_cannot_be_sent_for_approval(): void
    {
        $this->approvalPath();
        $paper = $this->draft();

        app(DocumentComposer::class)->issue($paper, $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a draft');

        app(DocumentComposer::class)->submitForApproval($paper->fresh(), $this->owner);
    }
}
