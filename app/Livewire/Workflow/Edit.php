<?php

namespace App\Livewire\Workflow;

use App\Models\Department;
use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflow\WorkflowVersioning;
use App\Support\CurrentCompany;
use App\Support\WorkflowConditions;
use App\Support\WorkflowSubjects;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * One approval path: what it approves, and who signs it, in order.
 *
 * Two things on this screen are not decoration.
 *
 * The first is that an approver is named by ROLE, by DEPARTMENT or by a
 * relationship to the record — never by picking a person, unless somebody
 * deliberately chooses to. A workflow that names a person is wrong the day
 * they leave, and nobody finds out until something has sat unapproved for a
 * week. The screen says so, and `user` is listed last.
 *
 * The second is that a step nobody can fill does not pass — it stalls, in
 * complete silence. So a step naming a role nobody holds is called out at the
 * moment it is written. It never blocks the save: a business may be writing
 * the path for a job it is about to advertise.
 */
class Edit extends Component
{
    public Workflow $workflow;

    /**
     * What just happened, in the user's words.
     *
     * Held on the component rather than flashed to the session because the two
     * things worth saying — that live approvals were set aside, and that a step
     * may have nobody in it — belong beside the step that caused them, and a
     * flash would be gone by the time the next edit is made.
     *
     * @var array<int, string>
     */
    public array $notices = [];

    // ── The workflow itself ─────────────────────────────────────────────
    public string $name = '';

    public string $description = '';

    public string $subjectType = '';

    // ── The step being written ──────────────────────────────────────────
    public bool $editingStep = false;

    public ?string $stepId = null;

    public string $stepName = '';

    public string $stepType = 'approval';

    public string $approverMode = 'role';

    public string $approverRole = '';

    public string $approverDepartmentId = '';

    public string $approverUserId = '';

    /** 'any' | 'all' | 'count' — 'count' turns on the number below. */
    public string $quorumMode = 'any';

    public int $quorumCount = 2;

    public string $dueDays = '';

    /** @var array<int, array{field: string, operator: string, value: string}> */
    public array $conditions = [];

    public function mount(Workflow $workflow): void
    {
        Gate::authorize('workflows.view');

        // Frozen copies are a record of rules an approval is still being
        // judged by. Editing one would rewrite the rules of something already
        // half decided, which is the exact thing freezing exists to prevent.
        abort_if($workflow->isArchivedVersion(), 404);

        $this->workflow = $workflow;
        $this->name = $workflow->name;
        $this->description = (string) $workflow->description;
        $this->subjectType = $workflow->subject_type;
    }

    // ── The workflow ────────────────────────────────────────────────────

    public function saveDetails(): void
    {
        Gate::authorize('workflows.manage');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'subjectType' => ['required', Rule::in(array_keys(WorkflowSubjects::CATALOGUE))],
        ]);

        /*
         * What a workflow approves is fixed once it has been used. Conditions
         * are written against the columns of one particular record, and every
         * approval already run carries this workflow's id — changing the
         * subject would leave a purchase request being explained by rules
         * about a contract. Copy it instead; that is what Duplicate is for.
         */
        if ($this->subjectType !== $this->workflow->subject_type
            && $this->workflow->instances()->exists()) {
            $this->addError('subjectType', 'This path has already been used, so what it approves cannot be changed. Duplicate it instead.');

            return;
        }

        $this->workflow->forceFill([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'subject_type' => $this->subjectType,
        ])->save();

        $this->dispatch('toast', message: 'Saved.');
    }

    public function toggleActive(): void
    {
        Gate::authorize('workflows.manage');

        if (! $this->workflow->is_active && $this->workflow->steps()->count() === 0) {
            $this->addError('steps', 'A workflow with no steps approves everything the moment it starts. Add a step first.');

            return;
        }

        $this->workflow->forceFill(['is_active' => ! $this->workflow->is_active])->save();
        $this->workflow->refresh();
    }

    public function makeDefault(): void
    {
        Gate::authorize('workflows.manage');

        if ($this->workflow->steps()->count() === 0) {
            $this->addError('steps', 'That would send every new record straight through with nobody asked.');

            return;
        }

        $this->workflow->forceFill(['is_default' => true, 'is_active' => true])->save();
        $this->workflow->refresh();

        $this->dispatch('toast', message: 'That is now what '.WorkflowSubjects::label($this->workflow->subject_type).' go through.');
    }

    // ── Steps ───────────────────────────────────────────────────────────

    public function addStep(): void
    {
        Gate::authorize('workflows.manage');

        $this->resetStepForm();
        $this->editingStep = true;
    }

    public function editStep(string $id): void
    {
        Gate::authorize('workflows.manage');

        $step = $this->workflow->steps()->findOrFail($id);

        $this->stepId = $step->id;
        $this->stepName = $step->name;
        $this->stepType = $step->type;
        $this->approverMode = $step->approver_mode;
        $this->approverRole = (string) $step->approver_role;
        $this->approverDepartmentId = (string) $step->approver_department_id;
        $this->approverUserId = $step->approver_user_id === null ? '' : (string) $step->approver_user_id;
        $this->dueDays = $step->due_days === null ? '' : (string) $step->due_days;

        $this->quorumMode = in_array($step->quorum, ['any', 'all'], true) ? $step->quorum : 'count';
        $this->quorumCount = $this->quorumMode === 'count' ? max(2, (int) $step->quorum) : 2;

        $this->conditions = collect($step->conditions ?? [])
            ->map(fn ($condition) => [
                'field' => (string) ($condition['field'] ?? ''),
                'operator' => (string) ($condition['operator'] ?? '='),
                'value' => (string) ($condition['value'] ?? ''),
            ])
            ->values()
            ->all();

        $this->editingStep = true;
    }

    public function cancelStep(): void
    {
        $this->resetStepForm();
        $this->editingStep = false;
    }

    public function addCondition(): void
    {
        $this->conditions[] = [
            'field' => WorkflowSubjects::fields($this->workflow->subject_type)[0] ?? '',
            'operator' => '>=',
            'value' => '',
        ];
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);

        $this->conditions = array_values($this->conditions);
    }

    public function saveStep(): void
    {
        Gate::authorize('workflows.manage');

        $this->validate([
            'stepName' => ['required', 'string', 'max:120'],
            'stepType' => ['required', Rule::in(array_keys(WorkflowStep::TYPES))],
            'approverMode' => ['required', Rule::in(array_keys(WorkflowStep::APPROVER_MODES))],
            'approverRole' => ['exclude_unless:approverMode,role', 'required', Rule::in(array_column($this->roles(), 'slug'))],
            'approverDepartmentId' => ['exclude_unless:approverMode,department', 'required', 'string'],
            'approverUserId' => ['exclude_unless:approverMode,user', 'required'],
            'quorumMode' => ['required', Rule::in(['any', 'all', 'count'])],
            'quorumCount' => ['integer', 'min:1', 'max:20'],
            'dueDays' => ['nullable', 'numeric', 'min:1', 'max:365'],
            'conditions.*.field' => ['required', 'string'],
            'conditions.*.operator' => ['required', Rule::in(WorkflowConditions::OPERATORS)],
            'conditions.*.value' => ['required', 'string', 'max:120'],
        ], [
            'stepName.required' => 'What is this step called?',
            'approverRole.required' => 'Which role signs this?',
            'approverDepartmentId.required' => 'Which department?',
            'approverUserId.required' => 'Which person?',
            'conditions.*.field.required' => 'Say which field the condition is about.',
            'conditions.*.value.required' => 'A condition needs something to compare against.',
        ]);

        // The old rules are copied aside before anything moves, so approvals
        // already running finish under the rules they started under. See
        // WorkflowVersioning — this is the whole reason that class exists.
        $frozen = app(WorkflowVersioning::class)->freeze($this->workflow);

        $attributes = [
            'name' => $this->stepName,
            'type' => $this->stepType,
            'approver_mode' => $this->approverMode,
            // Only the field that belongs to the chosen mode is kept. Leaving
            // a stale user id on a step now answered by a role would make the
            // stored rule disagree with the one on the screen.
            'approver_role' => $this->approverMode === 'role' ? $this->approverRole : null,
            'approver_department_id' => $this->approverMode === 'department' ? $this->approverDepartmentId : null,
            'approver_user_id' => $this->approverMode === 'user' ? (int) $this->approverUserId : null,
            'quorum' => match ($this->quorumMode) {
                'all' => 'all',
                'count' => (string) $this->quorumCount,
                default => 'any',
            },
            'conditions' => $this->cleanConditions(),
            'due_days' => $this->dueDays === '' ? null : (int) $this->dueDays,
        ];

        if ($this->stepId !== null) {
            $step = $this->workflow->steps()->findOrFail($this->stepId);
            $step->forceFill($attributes)->save();
        } else {
            $step = $this->workflow->steps()->create($attributes + [
                // Explicit: Model::create() does not backfill the column
                // default, so an unset position would land at 0 and the engine
                // — which walks from position 1 — would never reach the step.
                'position' => (int) ($this->workflow->steps()->max('position') ?? 0) + 1,
            ]);
        }

        $this->announce($frozen, $step);

        $this->workflow->refresh();
        $this->cancelStep();
    }

    /**
     * Remove a step.
     *
     * Deleting the row cascades its assignments away, which is exactly why the
     * running approvals are moved onto a frozen copy first: they keep their
     * step, their assignments and their place in the queue.
     */
    public function removeStep(string $id): void
    {
        Gate::authorize('workflows.manage');

        $step = $this->workflow->steps()->findOrFail($id);

        $frozen = app(WorkflowVersioning::class)->freeze($this->workflow);

        DB::transaction(function () use ($step) {
            $step->delete();

            $this->renumber();
        });

        $this->workflow->refresh();
        $this->announce($frozen, null);
    }

    public function moveUp(string $id): void
    {
        $this->swap($id, -1);
    }

    public function moveDown(string $id): void
    {
        $this->swap($id, 1);
    }

    protected function swap(string $id, int $direction): void
    {
        Gate::authorize('workflows.manage');

        $steps = $this->workflow->steps()->orderBy('position')->get();
        $index = $steps->search(fn (WorkflowStep $step) => $step->id === $id);

        if ($index === false || ! isset($steps[$index + $direction])) {
            return;
        }

        // Reordering moves an instance's position onto a different step, so
        // this is as structural a change as deleting one.
        $frozen = app(WorkflowVersioning::class)->freeze($this->workflow);

        $a = $steps[$index];
        $b = $steps[$index + $direction];

        DB::transaction(function () use ($a, $b) {
            $a->forceFill(['position' => $b->position])->save();
            $b->forceFill(['position' => $a->position])->save();
        });

        $this->workflow->refresh();
        $this->announce($frozen, null);
    }

    /** Positions stay 1..n and contiguous — the engine walks them one by one. */
    protected function renumber(): void
    {
        foreach ($this->workflow->steps()->orderBy('position')->get() as $index => $step) {
            $step->forceFill(['position' => $index + 1])->save();
        }
    }

    /**
     * Say what happened, including the two things that are easy to miss:
     * that live approvals were set aside, and that a step may have nobody in
     * it. Neither refuses the change.
     */
    protected function announce(?Workflow $frozen, ?WorkflowStep $step): void
    {
        $messages = ['Saved.'];

        if ($frozen !== null) {
            $messages[] = 'Approvals already under way were left on the old rules, so nobody has to start again.';
        }

        if ($step !== null) {
            $companyId = app(CurrentCompany::class)->id();

            $warning = $companyId === null
                ? null
                : app(WorkflowVersioning::class)->warningFor($step, $companyId);

            if ($warning !== null) {
                $messages[] = $warning;
            }
        }

        $this->notices = $messages;
    }

    /** @return array<int, array{field: string, operator: string, value: mixed}> */
    protected function cleanConditions(): array
    {
        return collect($this->conditions)
            ->filter(fn (array $condition) => ($condition['field'] ?? '') !== '')
            ->map(fn (array $condition) => [
                'field' => $condition['field'],
                'operator' => $condition['operator'],
                // Numbers stored as numbers. WorkflowConditions compares
                // numerically only when both sides look numeric, and a
                // threshold saved as the string "10000000" would still work —
                // but "5" against 10 as strings sorts the wrong way round, and
                // that is a limit that silently lets everything through.
                'value' => is_numeric($condition['value'])
                    ? $condition['value'] + 0
                    : $condition['value'],
            ])
            ->values()
            ->all();
    }

    protected function resetStepForm(): void
    {
        $this->reset([
            'stepId', 'stepName', 'stepType', 'approverMode', 'approverRole',
            'approverDepartmentId', 'approverUserId', 'quorumMode', 'quorumCount',
            'dueDays', 'conditions',
        ]);
    }

    /** @return array<int, array{slug: string, name: string}> */
    protected function roles(): array
    {
        return Role::query()
            ->orderBy('level')
            ->get(['slug', 'name'])
            ->map(fn (Role $role) => ['slug' => $role->slug, 'name' => $role->name])
            ->all();
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();
        $versioning = app(WorkflowVersioning::class);
        $companyId = $company?->id;

        $steps = $this->workflow->steps()->orderBy('position')->get();

        return view('livewire.workflow.edit', [
            'steps' => $steps,
            'stepWarnings' => $steps->mapWithKeys(fn (WorkflowStep $step) => [
                $step->id => $companyId === null ? null : $versioning->warningFor($step, $companyId),
            ]),
            'inFlight' => $versioning->inFlight($this->workflow),
            'types' => WorkflowStep::TYPES,
            'approverModes' => WorkflowStep::APPROVER_MODES,
            // Straight from the evaluator, so the form can never offer an
            // operator the engine would fail closed on.
            'operators' => WorkflowConditions::OPERATORS,
            'roles' => $this->roles(),
            'departments' => Department::query()->orderBy('name')->get(),
            'people' => $company?->users()->orderBy('name')->get(['users.id', 'users.name']) ?? collect(),
            'fields' => WorkflowSubjects::fields($this->workflow->subject_type),
            'subjectOptions' => WorkflowSubjects::options(),
            'subjectLabel' => WorkflowSubjects::label($this->workflow->subject_type),
            'canManage' => Gate::allows('workflows.manage'),
        ])->layout('components.layouts.app', ['title' => $this->workflow->name, 'active' => 'settings']);
    }
}
