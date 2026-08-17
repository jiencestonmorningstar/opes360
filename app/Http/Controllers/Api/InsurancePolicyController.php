<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\InsurancePolicyResource;
use App\Models\InsurancePolicy;
use App\Services\Insurance\Policies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The policy register over HTTP.
 *
 * Placing goes through the Policies service — the same one the screens use —
 * so the auto-renew-needs-a-notice-period rule and the cover-date sanity
 * checks hold here too. Binding, renewing, endorsing and cancelling stay on
 * the screens for now; a placed policy arrives `draft`, as it does there.
 */
class InsurancePolicyController extends ApiController
{
    public function __construct(private readonly Policies $policies) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('insurance.view');

        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['draft', 'active', 'lapsed', 'cancelled'])],
            'product_line' => ['sometimes', Rule::in(array_keys(InsurancePolicy::PRODUCT_LINES))],
            'holder_contact_id' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $policies = InsurancePolicy::query()
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['product_line']), fn ($q) => $q->where('product_line', $filters['product_line']))
            ->when(isset($filters['holder_contact_id']), fn ($q) => $q->where('holder_contact_id', $filters['holder_contact_id']))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return InsurancePolicyResource::collection($policies);
    }

    public function show(InsurancePolicy $policy): InsurancePolicyResource
    {
        $this->authorize('insurance.view');

        return InsurancePolicyResource::make($policy);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('insurance.manage');

        $data = $request->validate([
            'policy_number' => ['nullable', 'string', 'max:100'],
            'holder_contact_id' => ['required', 'string', 'exists:contacts,id'],
            'insurer_contact_id' => ['nullable', 'string', 'exists:contacts,id'],
            'product_line' => ['nullable', Rule::in(array_keys(InsurancePolicy::PRODUCT_LINES))],
            'premium' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'covers_from' => ['required', 'date'],
            'covers_to' => ['nullable', 'date'],
            'renewal_type' => ['nullable', Rule::in(array_keys(InsurancePolicy::RENEWAL_TYPES))],
            'renewal_term_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'notice_period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'owner_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        try {
            $policy = $this->policies->place($data, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return InsurancePolicyResource::make($policy)->response()->setStatusCode(201);
    }
}
