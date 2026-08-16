<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing asked for.
 *
 * The price is an estimate and is named as one. A requisition is written by
 * whoever needs the thing, before anyone has asked a supplier — treating that
 * guess as a price is how a purchase order ends up quoting a number nobody
 * agreed to sell at.
 */
class PurchaseRequisitionLine extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'estimated_unit_price' => 'decimal:2',
            'estimated_total' => 'decimal:2',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function recompute(): void
    {
        $this->estimated_total = round(
            (float) $this->quantity * (float) $this->estimated_unit_price,
            2
        );
    }
}
