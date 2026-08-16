<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of a recipe: make N of this.
 *
 * The order itself moves nothing. Completing it is what writes the stock
 * movements — components out, finished goods in — and every one of those
 * movements points back here, so the order is the answer to "where did the
 * planks go" the way a stocktake is the answer to "why did the count change".
 */
class ProductionOrder extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_IN_PROGRESS = 'in-progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => self::STATUS_PLANNED,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'total_cost' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function billOfMaterial(): BelongsTo
    {
        return $this->belongsTo(BillOfMaterial::class);
    }

    /** The finished product, copied off the recipe when the order was raised. */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionOrderLine::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    /** Still open: the two states from which completing or cancelling makes sense. */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PLANNED, self::STATUS_IN_PROGRESS], true);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
