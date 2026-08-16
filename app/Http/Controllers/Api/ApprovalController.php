<?php

namespace App\Http\Controllers\Api;

use App\Models\WorkflowAssignment;
use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Running approvals, and acting on them.
 *
 * Workflows themselves are not editable over the API, and that is not a gap to
 * be filled later. Whoever can edit a workflow can write themselves a path
 * with no approver in it — which is the same thing as being able to spend the
 * money — and that is not something a token should be able to do. What is here
 * is the running state, the outcome, and the one act an integration
 * legitimately performs: a decision on something it was actually asked about.
 */
class ApprovalController extends ApiController
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('workflows.view');

        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'in:running,approved,rejected,changes_requested,stalled,cancelled'],
            'subject_type' => ['sometimes', 'string', 'max:255'],
            'subject_id' => ['sometimes', 'string', 'max:255'],
        ]);

        $instances = WorkflowInstance::query()
            ->with('workflow')
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['subject_type']), fn ($q) => $q->where('subject_type', $filters['subject_type']))
            ->when(isset($filters['subject_id']), fn ($q) => $q->where('subject_id', $filters['subject_id']))
            ->latest()
            ->paginate(50);

        return response()->json([
            'data' => $instances->getCollection()->map(fn ($i) => $this->instance($i))->values(),
            'meta' => [
                'total' => $instances->total(),
                'per_page' => $instances->perPage(),
                'current_page' => $instances->currentPage(),
            ],
        ]);
    }

    public function show(WorkflowInstance $approval): JsonResponse
    {
        $this->authorize('workflows.view');

        abort_unless($approval->company_id === app(CurrentCompany::class)->id(), 404);

        return response()->json([
            'data' => $this->instance($approval->load('workflow', 'decisions.user')) + [
                'decisions' => $approval->decisions->map(fn ($d) => [
                    'id' => $d->id,
                    // The step name as it was when the decision was made.
                    // Renaming a workflow afterwards does not rewrite history.
                    'step' => $d->step_name,
                    'action' => $d->action,
                    'comment' => $d->comment,
                    'by' => $d->user?->name,
                    'acted_at' => $d->acted_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    /** What is waiting on this token's user. The same list /actions shows. */
    public function mine(Request $request): JsonResponse
    {
        $assignments = WorkflowAssignment::query()
            ->pending()
            ->forUser($request->user())
            ->with(['instance.workflow', 'step'])
            ->orderByRaw('due_on is null')
            ->orderBy('due_on')
            ->paginate(50);

        return response()->json([
            'data' => $assignments->getCollection()->map(fn ($a) => [
                'approval_id' => $a->workflow_instance_id,
                'workflow' => $a->instance?->workflow?->name,
                'step' => $a->step?->name,
                'subject_type' => $a->instance?->subject_type,
                'subject_id' => $a->instance?->subject_id,
                'due_on' => $a->due_on?->toDateString(),
                'overdue' => $a->isOverdue(),
            ])->values(),
            'meta' => ['total' => $assignments->total()],
        ]);
    }

    /**
     * Act on an assignment.
     *
     * No `authorize()` call, deliberately. Being asked IS the permission, and
     * the engine performs the only check that matters: it refuses a decision
     * from anybody without a pending assignment on this instance. A second
     * gate here would lock out an approver the engine itself had just chosen.
     */
    public function decide(Request $request, WorkflowInstance $approval): JsonResponse
    {
        abort_unless($approval->company_id === app(CurrentCompany::class)->id(), 404);

        $payload = $request->validate([
            'action' => ['required', 'string', 'in:approved,rejected,changes_requested'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $instance = $this->engine->act(
                $approval,
                $request->user(),
                $payload['action'],
                $payload['comment'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'error' => ['code' => 'not_assigned', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json(['data' => $this->instance($instance)]);
    }

    /** @return array<string, mixed> */
    protected function instance(WorkflowInstance $instance): array
    {
        return [
            'id' => $instance->id,
            'workflow' => $instance->workflow?->name,
            'subject_type' => $instance->subject_type,
            'subject_id' => $instance->subject_id,
            'status' => $instance->status,
            'position' => $instance->position,
            'started_at' => $instance->started_at?->toIso8601String(),
            'completed_at' => $instance->completed_at?->toIso8601String(),
        ];
    }
}
