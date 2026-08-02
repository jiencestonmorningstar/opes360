<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item on a count sheet.
 *
 * `counted_quantity` is null until somebody has actually looked. That is not
 * the same as zero, and conflating the two would write off every item nobody
 * got to — so an uncounted line is skipped at posting rather than treated as
 * an empty shelf.
 */
class StocktakeLine extends Model
{
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'book_quantity' => 'decimal:3',
            'counted_quantity' => 'decimal:3',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    /** Counted less book. Positive is stock nobody had recorded; negative is shrinkage. */
    public function variance(): float
    {
        if (! $this->isCounted()) {
            return 0.0;
        }

        return round((float) $this->counted_quantity - (float) $this->book_quantity, 3);
    }

    public function varianceValue(): float
    {
        return round($this->variance() * (float) $this->unit_cost, 2);
    }

    /** What the counted quantity is worth. An uncounted line is worth its book quantity. */
    public function value(): float
    {
        $quantity = $this->isCounted() ? (float) $this->counted_quantity : (float) $this->book_quantity;

        return round($quantity * (float) $this->unit_cost, 2);
    }
}
