<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\VipMembershipResource;
use App\Http\Resources\VipTierResource;
use App\Models\Contact;
use App\Models\VipMembership;
use App\Models\VipTier;
use App\Services\VipMemberships;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * The VIP programme over HTTP.
 *
 * Selling goes through VipMemberships, the same service the counter uses, so
 * the fee reaches the books by one route and the rule about extending an
 * existing term lives in one place. Nothing here writes a membership row
 * directly.
 *
 * Authorised by ability rather than by policy for tiers, because a tier is a
 * setting rather than a record somebody owns; memberships use the policy, so
 * another company's 404 rather than 403.
 */
class VipController extends ApiController
{
    public function __construct(private readonly VipMemberships $service) {}

    // ── Tiers ────────────────────────────────────────────────────────────

    public function tiers(Request $request): AnonymousResourceCollection
    {
        $this->authorize('vip.view');

        $filters = $request->validate([
            'active' => ['sometimes', 'boolean'],
        ]);

        return VipTierResource::collection(
            VipTier::query()
                ->when($filters['active'] ?? false, fn (Builder $q) => $q->where('is_active', true))
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
        );
    }

    public function storeTier(Request $request): JsonResponse
    {
        $this->authorize('vip.manage');

        $data = $request->validate($this->tierRules());

        $tier = VipTier::create($data + [
            'currency' => app(CurrentCompany::class)->get()?->currency ?? 'XAF',
            'is_active' => true,
        ]);

        return VipTierResource::make($tier)->response()->setStatusCode(201);
    }

    /**
     * Editing a tier changes what future sales get, never what a member was
     * already sold — memberships hold their own copy of the terms.
     */
    public function updateTier(Request $request, VipTier $tier): VipTierResource
    {
        $this->authorize('vip.manage');

        $tier->update($request->validate($this->tierRules(updating: true)));

        return VipTierResource::make($tier->fresh());
    }

    // ── Memberships ──────────────────────────────────────────────────────

    public function memberships(Request $request): AnonymousResourceCollection
    {
        $this->authorize('vip.view');

        $filters = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'contact_id' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $memberships = VipMembership::query()
            ->with('contact')
            ->when($filters['active'] ?? false, fn (Builder $q) => $q->live())
            ->when(isset($filters['contact_id']), fn (Builder $q) => $q->where('contact_id', $filters['contact_id']))
            ->latest('ends_on')
            ->paginate($filters['per_page'] ?? 25);

        return VipMembershipResource::collection($memberships);
    }

    public function showMembership(VipMembership $membership): VipMembershipResource
    {
        $this->authorize('view', $membership);

        return VipMembershipResource::make($membership->load('contact'));
    }

    /**
     * Sell a membership.
     *
     * Raises a real invoice for the fee, so this takes money and sits under the
     * `money` scope with an Idempotency-Key: a retry after a dropped connection
     * must not sell and charge for two memberships.
     */
    public function sell(Request $request): JsonResponse
    {
        $this->authorize('vip.sell');

        $data = $request->validate([
            'contact_id' => ['required', 'string', 'exists:contacts,id'],
            'tier_id' => ['required', 'string', 'exists:vip_tiers,id'],
        ]);

        $contact = Contact::findOrFail($data['contact_id']);
        $tier = VipTier::findOrFail($data['tier_id']);

        try {
            $membership = $this->service->sell($contact, $tier, $request->user());
        } catch (RuntimeException $e) {
            // A withdrawn tier, or no current company — refusals of the domain
            // rather than of the request's shape.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return VipMembershipResource::make($membership->load('contact'))
            ->response()->setStatusCode(201);
    }

    /**
     * Cancel a membership.
     *
     * The record is kept and the reason is required: a membership that stopped
     * with no explanation is the row somebody asks about a year later.
     * Refunding the fee, if it is owed, is a separate act through the payment
     * endpoints.
     */
    public function cancel(Request $request, VipMembership $membership): VipMembershipResource
    {
        $this->authorize('view', $membership);
        $this->authorize('vip.manage');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ], [
            'reason.required' => 'Say why this membership is being cancelled.',
        ]);

        $this->service->cancel($membership, $data['reason'], $request->user());

        return VipMembershipResource::make($membership->fresh()->load('contact'));
    }

    /** @return array<string, array<int, mixed>> */
    private function tierRules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:60'],
            'price' => [$required, 'numeric', 'min:0'],
            'period_months' => [$required, 'integer', 'min:1', 'max:120'],
            'discount_percent' => [$required, 'numeric', 'min:0', 'max:100'],
            'perks' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
