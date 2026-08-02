<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A count of what is actually on the shelves, and the entry it settles.
 *
 * Draft while it is being walked, posted once. Posting is the point of no
 * return: it writes an adjustment movement for every line that disagreed with
 * the ledger and posts the stock variation to the books. A count recorded in
 * error is voided, which reverses both — never edited, because the shelf was
 * counted on a day and that day does not change.
 */
class Stocktake extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const STATUSES = [
        'draft' => 'Counting',
        'posted' => 'Posted',
        'void' => 'Voided',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'counted_on' => 'date',
            'total_value' => 'decimal:2',
            'variance_value' => 'decimal:2',
            'opening_value' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StocktakeLine::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'stock_location_id');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
