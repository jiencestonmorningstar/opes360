<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\LoyaltyTransactionResource;
use App\Models\Contact;
use App\Services\LoyaltyLedger;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * A customer's points, and spending them.
 *
 * Loyalty has no policy class — it is a set of abilities over a customer
 * record rather than a model of its own — so authorisation here is by ability
 * string, the same way ExpenseController does it, and the same three slugs the
 * screens ask for: `loyalty.view`, `loyalty.redeem`, `loyalty.manage`. The
 * contact is checked separately through ContactPolicy, because a balance is
 * still somebody's customer record.
 *
 * Every change goes through LoyaltyLedger. Points are an append-only ledger
 * with `contacts.loyalty_points` as a cached sum; an endpoint that wrote that
 * column would produce a balance no row explains, and the ledger's own
 * docblock is explicit that when the two disagree the ledger is right. The
 * sufficiency check also lives inside the service, under a row lock — two
 * tills redeeming the last hundred points at the same moment is exactly the
 * case a caller-side check gets wrong.
 *
 * **Earning is not exposed, and neither is adjusting.** Points are earned as a
 * side effect of a payment, which already has an endpoint; an "earn" route
 * would be a way to mint them with no spend behind them. `adjust()` exists on
 * the service and is audited — it demands a note and records the actor — but it
 * is the goodwill gesture a manager makes in front of a customer, with a
 * screen and a name attached to it. Nothing has asked to do that over HTTP,
 * and handing a token the ability to conjure balances is not a default worth
 * shipping ahead of a use case.
 */
class LoyaltyController extends ApiController
{
    public function __construct(private readonly LoyaltyLedger $ledger) {}

    /**
     * The card and the balance.
     *
     * `value` is what the points are worth in the business's own currency at
     * today's rate — the number a till needs to take off a bill. It is
     * computed here rather than left to the caller because the rate is a
     * business setting that can change, and a client caching its own copy
     * would eventually discount by last month's.
     */
    public function show(Contact $contact): JsonResponse
    {
        $this->authorize('view', $contact);
        $this->authorize('loyalty.view');

        $company = app(CurrentCompany::class)->get();
        $points = (int) $contact->loyalty_points;

        return response()->json([
            'data' => [
                'contact_id' => $contact->id,
                'contact_name' => $contact->displayName(),
                'card_number' => $contact->loyalty_card_number,
                'card_issued_at' => $contact->loyalty_card_issued_at?->toIso8601String(),
                'points' => $points,
                'point_value' => $company?->loyaltyPointValue() ?? 0.0,
                'value' => round($points * ($company?->loyaltyPointValue() ?? 0.0), 2),
                'currency' => $company?->currency,
                'program_enabled' => (bool) ($company?->loyalty_enabled ?? false),
            ],
        ]);
    }

    /** The ledger behind that balance. */
    public function transactions(Request $request, Contact $contact): AnonymousResourceCollection
    {
        $this->authorize('view', $contact);
        $this->authorize('loyalty.view');

        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return LoyaltyTransactionResource::collection(
            $contact->loyaltyTransactions()->latest()->paginate($filters['per_page'] ?? 25)
        );
    }

    /**
     * Spend points.
     *
     * Refused with `422` when the balance is short — the service's message
     * names the customer and the number actually available, which is what the
     * person at the till has to say out loud.
     */
    public function redeem(Request $request, Contact $contact): JsonResponse
    {
        $this->authorize('view', $contact);
        $this->authorize('loyalty.redeem');

        $data = $request->validate([
            'points' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $transaction = $this->ledger->redeem(
                $contact,
                $data['points'],
                $data['note'] ?? null,
                $request->user(),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return LoyaltyTransactionResource::make($transaction)
            ->response()->setStatusCode(201);
    }
}
