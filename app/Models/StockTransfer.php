<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Stock moved from one place to another.
 *
 * Two movements per line — out of one location, into another — so the total
 * across all locations is unchanged. That invariant is the whole of what a
 * transfer means, and the reason it is appended rather than adjusted.
 */
class StockTransfer extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['moved_on' => 'date'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'from_location_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'to_location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function totalQuantity(): float
    {
        return round((float) $this->lines->sum('quantity'), 3);
    }
}
