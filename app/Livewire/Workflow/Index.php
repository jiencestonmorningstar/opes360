<?php

namespace App\Livewire\Workflow;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflow\WorkflowVersioning;
use App\Support\WorkflowSubjects;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Every approval path the business has, and who signs what.
 *
 * This is a control surface, not a settings page. Whoever can rewrite a
 * workflow can write themselves a path with no approver in it, which is the
 * same act as authorising the spend — so `workflows.view` shows the rules and
 * only `workflows.manage` changes them, and the two are enforced on every
 * method rather than only on the buttons.
 *
 * Grouped by what is being approved rather than listed flat, because the
 * question a business brings to this screen is almost never "what workflows do
 * we have" — it is "what happens when somebody asks to buy something", and the
 * answer to that is one group.
 */
class Index extends Component
{
    public bool $creating = false;

    public string $name = '';

    public string $subjectType = '';

    public function mount(): void
    {
        Gate::authorize('workflows.view');
    }

    public function startCreating(): void
    {
        Gate::authorize('workflows.manage');

        $this->creating = true;
        $this->name = '';
        $this->subjectType = array_key_first(WorkflowSubjects::CATALOGUE);
    }

    public function cancel(): void
    {
        $this->reset(['creating', 'name', 'subjectType']);
    }

    public function create(): void
    {
        Gate::authorize('workflows.manage');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'subjectType' => ['required', Rule::in(array_keys(WorkflowSubjects::CATALOGUE))],
        ], [
            'name.required' => 'Give it a name people will recognise.',
            'subjectType.required' => 'Say what this approves.',
        ]);

        /*
         * Created inactive and not default, with no steps.
         *
         * A brand new workflow has nobody in it, and a workflow with no steps
         * approves everything the instant it starts. Making it live before its
         * steps are written would be a path that waves through whatever it was
         * pointed at, which is the exact failure this whole module exists to
         * prevent. It goes live from its own screen, once it says something.
         */
        $workflow = Workflow::create([
            'name' => $this->name,
            'subject_type' => $this->subjectType,
            'is_active' => false,
            'is_default' => false,
            'created_by' => auth()->id(),
        ]);

        $this->reset(['creating', 'name', 'subjectType']);

        $this->redirect(route('workflows.edit', $workflow), navigate: true);
    }

    /**
     * Copy an existing path to start from.
     *
     * The commonest real edit is "the same as purchase requests, but the
     * director signs over five million". Retyping four steps to change one is
     * how a step gets left out.
     */
    public function duplicate(string $id): void
    {
        Gate::authorize('workflows.manage');

        $workflow = Workflow::definitions()->with('steps')->findOrFail($id);

        DB::transaction(function () use ($workflow) {
            $copy = Workflow::create([
                'name' => $workflow->name.' (copy)',
                'description' => $workflow->description,
                'subject_type' => $workflow->subject_type,
                'is_active' => false,
                'is_default' => false,
                'created_by' => auth()->id(),
            ]);

            foreach ($workflow->steps as $step) {
                // Every column named. Model::create() does not backfill a
                // column default, so a copy relying on the schema would come
                // back with a different type, quorum and position from the
                // thing it claims to be a copy of.
                WorkflowStep::create([
                    'workflow_id' => $copy->id,
                    'position' => $step->position,
                    'name' => $step->name,
                    'type' => $step->type,
                    'approver_mode' => $step->approver_mode,
                    'approver_role' => $step->approver_role,
                    'approver_department_id' => $step->approver_department_id,
                    'approver_user_id' => $step->approver_user_id,
                    'quorum' => $step->quorum,
                    'conditions' => $step->conditions,
                    'due_days' => $step->due_days,
                ]);
            }
        });

        $this->dispatch('toast', message: 'Copied. The copy is switched off until you turn it on.');
    }

    /**
     * Switch a path on or off.
     *
     * Nothing is frozen here and nothing needs to be: `is_active` decides
     * which workflow a NEW submission picks up. An approval already running
     * keeps running under the workflow it started with, switched off or not —
     * stopping it would strand whoever was asked to sign.
     */
    public function toggleActive(string $id): void
    {
        Gate::authorize('workflows.manage');

        $workflow = Workflow::definitions()->findOrFail($id);

        if (! $workflow->is_active && $workflow->steps()->count() === 0) {
            $this->addError('workflow', 'A workflow with no steps approves everything the moment it starts. Add a step first.');

            return;
        }

        $workflow->forceFill(['is_active' => ! $workflow->is_active])->save();
    }

    public function makeDefault(string $id): void
    {
        Gate::authorize('workflows.manage');

        $workflow = Workflow::definitions()->findOrFail($id);

        if ($workflow->steps()->count() === 0) {
            $this->addError('workflow', 'That workflow has no steps yet, so it would approve everything sent to it.');

            return;
        }

        // is_active too: the default is what a module asks for by name, and
        // `defaultFor` only returns active ones — a default that is switched
        // off answers nothing and reads like a bug.
        $workflow->forceFill(['is_default' => true, 'is_active' => true])->save();

        $this->dispatch('toast', message: 'That is now what '.WorkflowSubjects::label($workflow->subject_type).' go through.');
    }

    /**
     * Remove a path.
     *
     * Soft deleted, and only once nothing is halfway through it — the engine
     * reads its steps every time it advances, and a workflow that has gone
     * would leave whoever was asked to approve holding nothing. Anything in
     * flight is copied aside first so it can finish under the rules it began
     * under.
     */
    public function delete(string $id): void
    {
        Gate::authorize('workflows.manage');

        $workflow = Workflow::definitions()->findOrFail($id);

        app(WorkflowVersioning::class)->freeze($workflow);

        $workflow->delete();

        $this->dispatch('toast', message: 'That approval path has been removed.');
    }

    public function render(): View
    {
        $versioning = app(WorkflowVersioning::class);

        $workflows = Workflow::definitions()
            ->with('steps')
            ->withCount('steps')
            ->orderBy('name')
            ->get();

        return view('livewire.workflow.index', [
            'groups' => $workflows->groupBy('subject_type'),
            'subjects' => WorkflowSubjects::CATALOGUE,
            'subjectOptions' => WorkflowSubjects::options(),
            'inFlight' => $workflows->mapWithKeys(
                fn (Workflow $workflow) => [$workflow->id => $versioning->inFlight($workflow)]
            ),
            'warnings' => $workflows->mapWithKeys(
                fn (Workflow $workflow) => [$workflow->id => $versioning->warnings($workflow)]
            ),
        ])->layout('components.layouts.app', ['title' => 'Approval paths', 'active' => 'settings']);
    }
}
