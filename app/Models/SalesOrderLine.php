<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One promised item on a sales order.
 *
 * The four quantity columns tell the line's whole story:
 * ordered is what the customer asked for; reserved is what confirmation
 * could hold for them (live StockReservation rows referenced to this line);
 * delivered is what has physically gone, movement by movement; backordered
 * is the named remainder that could not be held. delivered + reserved +
 * backordered always accounts for ordered — nothing goes missing silently.
 */
class SalesOrderLine extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:3',
            'quantity_reserved' => 'decimal:3',
            'quantity_delivered' => 'decimal:3',
            'quantity_backordered' => 'decimal:3',
            'quantity_invoiced' => 'decimal:3',
            'unit_price' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** Delivered but not yet on any invoice — what the next invoice bills. */
    public function uninvoicedQuantity(): float
    {
        return round((float) $this->quantity_delivered - (float) $this->quantity_invoiced, 3);
    }

    public function isFullyDelivered(): bool
    {
        return (float) $this->quantity_delivered + 0.0005 >= (float) $this->quantity_ordered;
    }
}
