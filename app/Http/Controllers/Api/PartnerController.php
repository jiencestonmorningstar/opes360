<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\PartnerClientResource;
use App\Models\PartnerClient;
use App\Models\PartnerCommission;
use App\Models\PartnerPayout;
use App\Services\Partners\PartnerLedger;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The secretariat programme: a client book, what it has earned, and getting
 * paid.
 *
 * ── Why every route here can answer 403 for a reason that is not the role ───
 *
 * The programme is a property of the account rather than of the person. A
 * plain business has no client book to manage and no balance to withdraw, so
 * `AuthServiceProvider` denies every `partners.*` ability to any company that
 * is not a secretariat — before any role is consulted. An Owner of an ordinary
 * business is refused here exactly as a cashier would be, and that is the
 * intended answer rather than a gap.
 *
 * Authorised by ability rather than by policy, because that is how this module
 * already works — there is no PartnerPolicy and the screens ask
 * `partners.view` and friends directly.
 */
class PartnerController extends ApiController
{
    public function __construct(private readonly PartnerLedger $ledger) {}

    // ── The client book ──────────────────────────────────────────────────

    public function clients(Request $request): AnonymousResourceCollection
    {
        $this->authorize('partners.view');

        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'converted' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $clients = PartnerClient::query()
            ->when(isset($filters['q']), function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($filters['q'])).'%';

                $q->where(fn (Builder $inner) => $inner
                    ->where('name', 'like', $term)
                    ->orWhere('contact_name', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when(isset($filters['converted']), fn (Builder $q) => $filters['converted']
                ? $q->whereNotNull('converted_at')
                : $q->whereNull('converted_at'))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        return PartnerClientResource::collection($clients);
    }

    public function storeClient(Request $request): JsonResponse
    {
        $this->authorize('partners.manage');

        $data = $request->validate($this->clientRules());

        $client = PartnerClient::create($this->attributesFrom($data));

        return PartnerClientResource::make($client)->response()->setStatusCode(201);
    }

    public function showClient(PartnerClient $client): PartnerClientResource
    {
        $this->authorize('partners.view');

        return PartnerClientResource::make($client);
    }

    public function updateClient(Request $request, PartnerClient $client): PartnerClientResource
    {
        $this->authorize('partners.manage');

        $data = $request->validate($this->clientRules(updating: true));

        $client->update($this->attributesFrom($data));

        return PartnerClientResource::make($client->fresh());
    }

    // ── Earnings ─────────────────────────────────────────────────────────

    /**
     * What the programme has earned and what is withdrawable now.
     *
     * Straight from PartnerLedger, the same service the earnings screen reads,
     * so a figure pulled over HTTP and one shown in the app cannot differ.
     */
    public function earnings(): JsonResponse
    {
        $this->authorize('partners.view');

        return response()->json([
            'data' => $this->ledger->summary(app(CurrentCompany::class)->get()),
        ]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $this->authorize('partners.view');

        $filters = $request->validate([
            'status' => ['sometimes', 'string', 'max:30'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $commissions = PartnerCommission::query()
            ->when(isset($filters['status']), fn (Builder $q) => $q->where('status', $filters['status']))
            ->latest()
            ->paginate($filters['per_page'] ?? 25);

        $commissions->getCollection()->transform(fn (PartnerCommission $commission) => [
            'id' => $commission->id,
            'amount' => (float) $commission->amount,
            'base_amount' => (float) $commission->base_amount,
            'rate' => (float) $commission->rate,
            'currency' => $commission->currency,
            'status' => $commission->status,
            'earned_at' => $commission->created_at?->toIso8601String(),
        ]);

        return response()->json($commissions->toArray());
    }

    // ── Getting paid ─────────────────────────────────────────────────────

    public function payouts(Request $request): JsonResponse
    {
        $this->authorize('partners.view');

        $payouts = PartnerPayout::query()
            ->latest()
            ->paginate($request->integer('per_page') ?: 25);

        $payouts->getCollection()->transform(fn (PartnerPayout $payout) => [
            'id' => $payout->id,
            'amount' => (float) $payout->amount,
            'currency' => $payout->currency,
            'status' => $payout->status,
            'method' => $payout->method,
            // The destination is where somebody's money is sent. Returned
            // because the partner needs to confirm what they asked for, and
            // it is their own account rather than a third party's.
            'destination' => $payout->destination,
            'requested_at' => $payout->created_at?->toIso8601String(),
            'settled_at' => $payout->settled_at?->toIso8601String(),
        ]);

        return response()->json($payouts->toArray());
    }

    /**
     * Ask for the balance to be paid out.
     *
     * The amount is not a parameter. It is recomputed from the ledger at the
     * moment of the request, because a balance a client read a few minutes ago
     * is not a promise, and the figure a partner is paid has to be the one the
     * ledger says now. That also removes the obvious attack: an amount a
     * caller could name is an amount a caller could inflate.
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $this->authorize('partners.withdraw');

        $data = $request->validate([
            'method' => ['required', Rule::in(['mtn', 'orange', 'bank'])],
            'destination' => ['required', 'string', 'max:120'],
        ], [
            'destination.required' => 'Where should the money go? A mobile money number, or an account.',
        ]);

        $company = app(CurrentCompany::class)->get();
        $balance = $this->ledger->balance($company);
        $minimum = (int) config('opes.partners.payout_minimum');

        if ($balance < $minimum) {
            return response()->json([
                'message' => 'Your balance is below the minimum for a payout.',
                'balance' => $balance,
                'minimum' => $minimum,
            ], 422);
        }

        $payout = PartnerPayout::create([
            'amount' => $balance,
            'currency' => config('opes.partners.currency', 'XAF'),
            'status' => 'requested',
            'method' => $data['method'],
            'destination' => trim($data['destination']),
            'requested_by' => $request->user()->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $payout->id,
                'amount' => (float) $payout->amount,
                'currency' => $payout->currency,
                'status' => $payout->status,
                'method' => $payout->method,
                'destination' => $payout->destination,
                'requested_at' => $payout->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** @return array<string, array<int, mixed>> */
    private function clientRules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'industry' => ['nullable', 'string', 'max:60'],
            'city' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributesFrom(array $data): array
    {
        $attributes = [];

        foreach (['name', 'contact_name', 'phone', 'industry', 'city', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field] === null ? null : trim((string) $data[$field]);
            }
        }

        if (array_key_exists('email', $data)) {
            $attributes['email'] = $data['email'] === null ? null : mb_strtolower(trim($data['email']));
        }

        return $attributes;
    }
}
