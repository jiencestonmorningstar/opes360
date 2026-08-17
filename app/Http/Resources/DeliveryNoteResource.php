<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The wire shape of a delivery note — the paper a delivery wrote. */
class DeliveryNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status,
            'sales_order_id' => $this->sales_order_id,
            'delivered_on' => $this->delivered_on instanceof \DateTimeInterface
                ? $this->delivered_on->format('Y-m-d')
                : $this->delivered_on,
            'stock_location_id' => $this->stock_location_id,
            'note' => $this->note,
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id,
                'sales_order_line_id' => $line->sales_order_line_id,
                'item_id' => $line->item_id,
                'description' => $line->description,
                'unit' => $line->unit,
                'quantity' => (float) $line->quantity,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
