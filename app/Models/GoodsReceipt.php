<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What actually turned up, which is rarely exactly what was ordered.
 *
 * A receipt can exist without a purchase order. Goods arrive unordered more
 * often than anyone admits, and refusing to record them would push a business
 * back to paper for precisely the deliveries it most needs a trail for.
 */
class GoodsReceipt extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_on' => 'date'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('sort_order');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** What the delivery was worth at the prices actually charged. */
    public function value(): float
    {
        return round($this->lines->sum(
            fn (GoodsReceiptLine $line) => (float) $line->quantity * (float) ($line->unit_cost ?? 0)
        ), 2);
    }
}
