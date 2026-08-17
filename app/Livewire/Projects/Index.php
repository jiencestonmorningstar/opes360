<?php

namespace App\Livewire\Projects;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The projects workspace: search, filter by status, create, and the
 * essentials for each — client, manager, budget, cost so far against it.
 */
class Index extends Component
{
    use AuthorizesRequests;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = '';

    public bool $creating = false;

    public string $name = '';

    public string $code = '';

    public ?float $budget = null;

    public bool $isBillable = true;

    /** Which project's tasks and milestones panel is unfolded. */
    public ?string $open = null;

    // ── Task form ───────────────────────────────────────────────────────
    public string $taskTitle = '';

    public string $taskDueOn = '';

    public string $taskMilestoneId = '';

    // ── Milestone form ──────────────────────────────────────────────────
    public string $milestoneName = '';

    public string $milestoneDueOn = '';

    /**
     * The transitions a human can ask for, from each state.
     *
     * Cancelled is terminal and completed nearly so — reviving finished work
     * would silently reopen a budget somebody has already reported on. The
     * one deliberate liberty: planning can complete directly, because a
     * two-day job is real and forcing it through 'active' first is ceremony.
     */
    protected const TRANSITIONS = [
        'planning' => ['active', 'completed', 'cancelled'],
        'active' => ['on_hold', 'completed', 'cancelled'],
        'on_hold' => ['active', 'completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:40'],
            'budget' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function save(): void
    {
        $this->authorize('create', Project::class);

        $this->validate();

        Project::create([
            'name' => $this->name,
            'code' => $this->code ?: null,
            'budget' => $this->budget,
            'is_billable' => $this->isBillable,
            'status' => 'planning',
            'created_by' => auth()->id(),
        ]);

        $this->reset(['name', 'code', 'budget', 'creating']);
        $this->isBillable = true;
    }

    public function archive(string $id): void
    {
        $this->transition($id, 'cancelled');
    }

    /**
     * Move a project along its lifecycle.
     *
     * Completing refuses while tasks are still open: "completed" is a claim
     * the task list must agree with, or the status filter becomes a place to
     * hide unfinished work. Cancelling does not check — abandoning a project
     * abandons its tasks with it, and that is the point of cancelling.
     */
    public function transition(string $id, string $to): void
    {
        $project = Project::findOrFail($id);

        $this->authorize('update', $project);

        if (! in_array($to, self::TRANSITIONS[$project->status] ?? [], true)) {
            $this->addError('projects', 'A '.strtolower(Project::STATUSES[$project->status] ?? $project->status).' project cannot be moved to '.strtolower(Project::STATUSES[$to] ?? $to).'.');

            return;
        }

        if ($to === 'completed') {
            $unfinished = $project->tasks()->open()->count();

            if ($unfinished > 0) {
                $this->addError('projects', $unfinished === 1
                    ? 'One task is still open — finish it or cancel the project instead.'
                    : "{$unfinished} tasks are still open — finish them or cancel the project instead.");

                return;
            }
        }

        $project->update([
            'status' => $to,
            // The close date records when work actually ended, either way it
            // ended. Reset on the (planning-time) other transitions so a date
            // never outlives the state that wrote it.
            'closed_on' => in_array($to, ['completed', 'cancelled'], true) ? now()->toDateString() : null,
        ]);
    }

    // ── Tasks and milestones ────────────────────────────────────────────

    public function toggleOpen(string $id): void
    {
        $this->open = $this->open === $id ? null : $id;
        $this->reset('taskTitle', 'taskDueOn', 'taskMilestoneId', 'milestoneName', 'milestoneDueOn');
        $this->resetErrorBag();
    }

    public function addTask(): void
    {
        $project = Project::findOrFail($this->open);

        $this->authorize('update', $project);

        $this->validate([
            'taskTitle' => ['required', 'string', 'max:160'],
            'taskDueOn' => ['nullable', 'date'],
        ]);

        // The milestone must be this project's own — the id comes off a form
        // field, and filing work under another project's milestone would show
        // up as phantom progress on that project's panel.
        $milestoneId = $this->taskMilestoneId !== ''
            ? $project->milestones()->findOrFail($this->taskMilestoneId)->id
            : null;

        ProjectTask::create([
            'project_id' => $project->id,
            'milestone_id' => $milestoneId,
            'title' => trim($this->taskTitle),
            'status' => 'todo',
            'due_on' => $this->taskDueOn ?: null,
            'sort_order' => $project->tasks()->count(),
            'created_by' => auth()->id(),
        ]);

        $this->reset('taskTitle', 'taskDueOn', 'taskMilestoneId');
    }

    public function moveTask(string $taskId, string $to): void
    {
        $task = ProjectTask::findOrFail($taskId);

        $this->authorize('update', $task->project);

        // Any-to-any between the four states is fine — done work gets undone,
        // blocked work unblocks — but only between the four real states.
        if (! array_key_exists($to, ProjectTask::STATUSES)) {
            return;
        }

        $task->update([
            'status' => $to,
            'completed_at' => $to === 'done' ? now() : null,
        ]);
    }

    public function addMilestone(): void
    {
        $project = Project::findOrFail($this->open);

        $this->authorize('update', $project);

        $this->validate([
            'milestoneName' => ['required', 'string', 'max:160'],
            'milestoneDueOn' => ['nullable', 'date'],
        ]);

        ProjectMilestone::create([
            'project_id' => $project->id,
            'name' => trim($this->milestoneName),
            'due_on' => $this->milestoneDueOn ?: null,
            'sort_order' => $project->milestones()->count(),
        ]);

        $this->reset('milestoneName', 'milestoneDueOn');
    }

    public function completeMilestone(string $id): void
    {
        $milestone = ProjectMilestone::findOrFail($id);

        $this->authorize('update', $milestone->project);

        $milestone->update(['completed_at' => $milestone->isComplete() ? null : now()]);
    }

    public function render(): View
    {
        $this->authorize('viewAny', Project::class);

        // Paginated, never unbounded (§60): a business with two hundred
        // projects must not send all two hundred to the browser at once.
        $projects = Project::query()
            ->with(['contact', 'manager', 'company'])
            ->when($this->search !== '', fn ($q) => $q
                ->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('code', 'like', "%{$this->search}%")))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->latest()
            ->paginate(20);

        return view('livewire.projects.index', [
            'projects' => $projects,
            // Tasks and milestones only for the one unfolded project — the
            // list stays a list, not two hundred hidden kanbans.
            'openProject' => $this->open
                ? Project::query()->with(['tasks.assignee', 'tasks.milestone', 'milestones'])->find($this->open)
                : null,
            'taskStatuses' => ProjectTask::STATUSES,
        ])->layout('components.layouts.app', ['title' => 'Projects', 'active' => 'projects']);
    }
}
