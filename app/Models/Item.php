<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\Syncable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;
    use Syncable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'reorder_level' => 'decimal:3',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function scopeProducts(Builder $query): Builder
    {
        return $query->where('type', 'product');
    }

    public function scopeServices(Builder $query): Builder
    {
        return $query->where('type', 'service');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Stock is the sum of the append-only movement ledger, never a stored column.
     * If this becomes a hot query, add a periodically-refreshed snapshot table
     * rather than denormalising a mutable quantity back onto this model — that
     * would reintroduce the concurrency bug the ledger exists to avoid.
     */
    public function stockOnHand(): float
    {
        return (float) $this->movements()->sum('quantity');
    }

    public function isLowStock(): bool
    {
        if (! $this->track_stock || $this->reorder_level === null) {
            return false;
        }

        return $this->stockOnHand() <= (float) $this->reorder_level;
    }
}
