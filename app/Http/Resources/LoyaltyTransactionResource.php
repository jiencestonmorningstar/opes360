<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the loyalty ledger.
 *
 * `points` is signed — earns and positive adjustments are +, redemptions are −
 * — and `balance_after` is the balance the row itself produced. Both are stored
 * rather than derived, so a caller paging backwards through the ledger sees the
 * same numbers the till printed at the time.
 */
class LoyaltyTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'type' => $this->type,
            'points' => (int) $this->points,
            'balance_after' => (int) $this->balance_after,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
