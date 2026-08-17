<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\InsuranceClaimResource;
use App\Models\InsuranceClaim;
use App\Models\InsurancePolicy;
use App\Services\Insurance\Claims;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Claims over HTTP: first notification of loss, and reading the register.
 *
 * Opening is nested under the policy so the incident-inside-cover check runs
 * against the right dates. Settling and rejecting stay off the API: the
 * settlement decision belongs to the workflow engine, and the money act is
 * the screens' with `insurance.settle` behind it.
 */
class InsuranceClaimController extends ApiController
{
    public function __construct(private readonly Claims $claims) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('insurance.view');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(array_keys(InsuranceClaim::STATUSES))],
            'policy_id' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $claims = InsuranceClaim::query()
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['policy_id']), fn ($q) => $q->where('insurance_policy_id', $filters['policy_id']))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return InsuranceClaimResource::collection($claims);
    }

    public function show(InsuranceClaim $claim): InsuranceClaimResource
    {
        $this->authorize('insurance.view');

        return InsuranceClaimResource::make($claim);
    }

    public function store(Request $request, InsurancePolicy $policy): JsonResponse
    {
        $this->authorize('insurance.manage');

        $data = $request->validate([
            'incident_on' => ['required', 'date'],
            'description' => ['required', 'string', 'max:10000'],
            'claim_number' => ['nullable', 'string', 'max:100'],
            'claimed_amount' => ['nullable', 'numeric', 'min:0'],
            'reported_on' => ['nullable', 'date'],
        ]);

        try {
            $claim = $this->claims->open($policy, $data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return InsuranceClaimResource::make($claim)->response()->setStatusCode(201);
    }
}
