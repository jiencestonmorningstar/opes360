<?php

namespace App\Livewire\Workflow;

use App\Models\User;
use App\Models\WorkflowAssignment;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

/**
 * Everything waiting on you, from every module, in one list.
 *
 * There is no permission gate on this screen, and that is deliberate: being
 * assigned IS the permission. A gate here would mean somebody the engine had
 * just asked to approve an invoice could not open the page telling them so.
 *
 * Every action re-enters the engine rather than deciding anything itself. The
 * engine already refuses a decision from somebody who was not asked, and one
 * check in one place beats the same check written twice slightly differently.
 */
class Inbox extends Component
{
    use WithPagination;

    public string $comment = '';

    public ?string $acting = null;

    public function approve(string $instanceId): void
    {
        $this->decide($instanceId, 'approved');
    }

    public function reject(string $instanceId): void
    {
        $this->decide($instanceId, 'rejected');
    }

    public function requestChanges(string $instanceId): void
    {
        $this->decide($instanceId, 'changes_requested');
    }

    public function delegate(string $instanceId, int $toUserId): void
    {
        $assignment = $this->assignment($instanceId);

        app(WorkflowEngine::class)->delegate(
            $assignment->instance,
            $this->user(),
            User::findOrFail($toUserId),
            $this->comment ?: null,
        );

        $this->reset(['comment', 'acting']);
    }

    protected function decide(string $instanceId, string $action): void
    {
        $assignment = $this->assignment($instanceId);

        app(WorkflowEngine::class)->act(
            $assignment->instance,
            $this->user(),
            $action,
            $this->comment ?: null,
        );

        $this->reset(['comment', 'acting']);
    }

    /**
     * The assignment must be one of this user's own outstanding ones.
     *
     * Looked up by (instance, user) rather than by assignment id, so a
     * guessed or stale id from the page cannot reach somebody else's row.
     */
    protected function assignment(string $instanceId): WorkflowAssignment
    {
        $assignment = WorkflowAssignment::query()
            ->pending()
            ->forUser($this->user())
            ->where('workflow_instance_id', $instanceId)
            ->first();

        if ($assignment === null) {
            throw new RuntimeException('You have not been asked to act on this.');
        }

        return $assignment;
    }

    protected function user(): User
    {
        return auth()->user();
    }

    public function render(): View
    {
        // Paginated, never unbounded: a business with a backlog must not send
        // its whole approval queue to the browser.
        $assignments = WorkflowAssignment::query()
            ->pending()
            ->forUser($this->user())
            ->with(['instance.subject', 'instance.workflow', 'step', 'delegatedFrom'])
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->latest()
            ->paginate(20);

        return view('livewire.workflow.inbox', [
            'assignments' => $assignments,
        ])->layout('components.layouts.app', ['title' => 'My actions', 'active' => 'actions']);
    }
}
