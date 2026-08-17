<?php

namespace App\Services\Workflow;

use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Services\DocumentComposer;
use App\Services\Documents\DocumentLinker;
use App\Services\Documents\RecordDocumentComposer;
use Illuminate\Database\Eloquent\Model;

/**
 * The `compose_document` workflow step type's one handler — §5 item 3 of the
 * Documents completion plan.
 *
 * Composes through DocumentComposer exactly as every other creation route
 * does (no second numbering/versioning/hashing path), then links the new
 * draft to the workflow's own subject through DocumentLinker — the same
 * table a document started from a Contact or Contract is linked through.
 * When the subject itself has a registered field-context key (a Contract,
 * say), that context is passed through too, so `{{ contract.title }}` in the
 * template resolves without anybody wiring it by hand.
 *
 * Deliberately produces a *draft*, never an issued document: a workflow step
 * that both drafted and immediately issued a document would be a second,
 * silent issuing path alongside DocumentComposer::issue(), which requires a
 * user's deliberate act.
 */
class ComposeDocumentStep
{
    public function __construct(
        protected DocumentComposer $composer,
        protected RecordDocumentComposer $recordComposer,
        protected DocumentLinker $linker,
    ) {}

    public function handle(WorkflowInstance $instance, WorkflowStep $step): void
    {
        // A step type left half-configured composes nothing rather than
        // throwing and stalling every workflow that step sits inside — the
        // same "never break the record's own progress" reasoning ActionRunner
        // and the automation engine already apply to a broken rule.
        if (blank($step->compose_template)) {
            return;
        }

        $subject = $instance->subject;

        if (! $subject instanceof Model || $subject->getAttribute('company_id') === null) {
            return;
        }

        $company = Company::find($subject->getAttribute('company_id'));

        if ($company === null) {
            return;
        }

        $contextKey = $this->recordComposer->contextKeyFor($subject);
        $context = $contextKey !== null ? [$contextKey => $subject] : [];

        $user = User::find($instance->started_by);

        $document = BusinessDocument::create([
            'template' => $step->compose_template,
            'title' => $step->name,
            'fields' => [],
            'body' => $this->composer->merge($step->compose_template, [], $company, $context),
            'status' => 'draft',
            'created_by' => $user?->id,
        ]);

        $this->linker->attach($document, $subject, $step->compose_link_role ?: 'about', $user);
    }
}
