<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\WorkflowResource;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflow\WorkflowVersioning;
use App\Support\WorkflowConditions;
use App\Support\WorkflowSubjects;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Approval-path definitions over HTTP.
 *
 * The same rules the admin screens enforce, because they are correctness
 * rules and not screen conveniences:
 *
 *  - a new workflow arrives inactive and non-default, with no steps — a path
 *    with no steps approves everything the instant it starts;
 *  - what a workflow approves cannot change once it has been used;
 *  - any change to the steps freezes the current definition first, through
 *    WorkflowVersioning, so approvals already running finish under the rules
 *    they started under;
 *  - frozen copies never appear here and cannot be edited.
 *
 * `workflows.manage` guards every write — whoever can rewrite a path can
 * write themselves one with no approver in it.
 */
class WorkflowRuleController extends ApiController
{
    public function __construct(private readonly WorkflowVersioning $versioning) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('workflows.view');

        $filters = $request->validate([
            'subject_type' => ['sometimes', 'string'],
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $workflows = Workflow::query()
            ->definitions()
            ->with('steps')
            ->when(isset($filters['subject_type']), fn ($q) => $q->forSubject($filters['subject_type']))
            ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);

        return WorkflowResource::collection($workflows);
    }

    public function show(Workflow $workflow): WorkflowResource|JsonResponse
    {
        $this->authorize('workflows.view');

        // A frozen copy is a record, not a definition — the same 404 the
        // edit screen answers with.
        abort_if($workflow->isArchivedVersion(), 404);

        return WorkflowResource::make($workflow->load('steps'));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('workflows.manage');

        $data = $request->validate(array_merge([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'subject_type' => ['required', Rule::in(array_keys(WorkflowSubjects::CATALOGUE))],
        ], $this->stepRules(required: false)));

        $workflow = DB::transaction(function () use ($request, $data) {
            $workflow = Workflow::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'subject_type' => $data['subject_type'],
                // Inactive and not default until it says something — a path
                // with no steps waves through whatever it is pointed at.
                'is_active' => false,
                'is_default' => false,
                'created_by' => $request->user()->id,
            ]);

            $this->writeSteps($workflow, $data['steps'] ?? []);

            return $workflow;
        });

        return WorkflowResource::make($workflow->load('steps'))->response()->setStatusCode(201);
    }

    public function update(Request $request, Workflow $workflow): WorkflowResource|JsonResponse
    {
        $this->authorize('workflows.manage');

        abort_if($workflow->isArchivedVersion(), 404);

        $data = $request->validate(array_merge([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'subject_type' => ['sometimes', Rule::in(array_unique(array_merge(
                array_keys(WorkflowSubjects::CATALOGUE),
                [$workflow->subject_type],
            )))],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ], $this->stepRules(required: false)));

        // What a workflow approves is fixed once it has been used: every
        // approval already run carries this workflow's id, and conditions are
        // written against one record's columns. Duplicate it instead.
        if (isset($data['subject_type'])
            && $data['subject_type'] !== $workflow->subject_type
            && $workflow->instances()->exists()) {
            return response()->json([
                'message' => 'This path has already been used, so what it approves cannot be changed. Duplicate it instead.',
            ], 422);
        }

        $stepCountAfter = array_key_exists('steps', $data)
            ? count($data['steps'])
            : $workflow->steps()->count();

        if (($request->boolean('is_active') || $request->boolean('is_default'))
            && $stepCountAfter === 0) {
            return response()->json([
                'message' => 'A workflow with no steps approves everything the moment it starts. Add a step first.',
            ], 422);
        }

        DB::transaction(function () use ($workflow, $data, $request) {
            if (array_key_exists('steps', $data)) {
                // Copy-on-write before the steps move: approvals already
                // running keep their step rows and finish under the old rules.
                $this->versioning->freeze($workflow);

                $workflow->steps()->delete();
                $this->writeSteps($workflow, $data['steps']);
            }

            $fill = [];

            foreach (['name', 'description', 'subject_type'] as $field) {
                if (array_key_exists($field, $data)) {
                    $fill[$field] = $data[$field];
                }
            }

            if ($request->has('is_active')) {
                $fill['is_active'] = $request->boolean('is_active');
            }

            if ($request->has('is_default') && $request->boolean('is_default')) {
                // The default is what a module asks for by name, and only an
                // active workflow answers.
                $fill['is_default'] = true;
                $fill['is_active'] = true;
            }

            if ($fill !== []) {
                $workflow->forceFill($fill)->save();
            }
        });

        return WorkflowResource::make($workflow->refresh()->load('steps'));
    }

    /** @return array<string, array<int, mixed>> */
    private function stepRules(bool $required): array
    {
        return [
            'steps' => [$required ? 'required' : 'sometimes', 'array'],
            'steps.*.name' => ['required', 'string', 'max:120'],
            'steps.*.type' => ['nullable', Rule::in(array_keys(WorkflowStep::TYPES))],
            'steps.*.approver_mode' => ['required', Rule::in(array_keys(WorkflowStep::APPROVER_MODES))],
            'steps.*.approver_role' => ['nullable', 'string', 'max:80'],
            'steps.*.approver_department_id' => ['nullable', 'string'],
            'steps.*.approver_user_id' => ['nullable', 'integer'],
            'steps.*.quorum' => ['nullable', 'string', 'max:10'],
            'steps.*.due_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'steps.*.conditions' => ['nullable', 'array'],
            'steps.*.conditions.*.field' => ['required', 'string'],
            'steps.*.conditions.*.operator' => ['required', Rule::in(WorkflowConditions::OPERATORS)],
            'steps.*.conditions.*.value' => ['required'],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $steps */
    private function writeSteps(Workflow $workflow, array $steps): void
    {
        foreach (array_values($steps) as $index => $step) {
            $workflow->steps()->create([
                // Explicit: the engine walks from position 1, and create()
                // does not backfill a column default.
                'position' => $index + 1,
                'name' => $step['name'],
                'type' => $step['type'] ?? 'approval',
                'approver_mode' => $step['approver_mode'],
                // Only the field belonging to the chosen mode is kept, so the
                // stored rule cannot disagree with the one that was sent.
                'approver_role' => $step['approver_mode'] === 'role' ? ($step['approver_role'] ?? null) : null,
                'approver_department_id' => $step['approver_mode'] === 'department' ? ($step['approver_department_id'] ?? null) : null,
                'approver_user_id' => $step['approver_mode'] === 'user' ? ($step['approver_user_id'] ?? null) : null,
                'quorum' => $step['quorum'] ?? 'any',
                'conditions' => $step['conditions'] ?? null,
                'due_days' => $step['due_days'] ?? null,
            ]);
        }
    }
}
