<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The wire shape of a production order. Costs are null until completion —
 * they are written from the valuation at the moment the goods actually move.
 */
class ProductionOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'bill_of_material_id' => $this->bill_of_material_id,
            'item_id' => $this->item_id,
            'quantity' => (float) $this->quantity,
            'stock_location_id' => $this->stock_location_id,
            'note' => $this->note,
            'total_cost' => $this->total_cost !== null ? (float) $this->total_cost : null,
            'unit_cost' => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'quantity_required' => (float) $line->quantity_required,
                'quantity_consumed' => $line->quantity_consumed !== null ? (float) $line->quantity_consumed : null,
                'unit_cost' => $line->unit_cost !== null ? (float) $line->unit_cost : null,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
