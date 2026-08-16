<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A lot of stock the business can name: a batch number, or a single serial.
 *
 * Carries identity only. How much of it is left is the sum of the movements
 * pointing at it — a quantity column here would be a mutable total, the very
 * thing the append-only movement ledger exists to avoid.
 */
class StockBatch extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    public const KIND_BATCH = 'batch';

    public const KIND_SERIAL = 'serial';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'received_on' => 'date',
            'manufactured_on' => 'date',
            'unit_cost' => 'decimal:2',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What is left of this lot, everywhere. */
    public function quantity(): float
    {
        if (array_key_exists('batch_quantity', $this->attributes)) {
            return round((float) $this->attributes['batch_quantity'], 3);
        }

        return round((float) $this->movements()->sum('quantity'), 3);
    }

    /** Load remaining quantities in one aggregate — a batch list is where the N+1 gets written. */
    public function scopeWithQuantity(Builder $query): Builder
    {
        return $query->withSum('movements as batch_quantity', 'quantity');
    }

    public function scopeOnHand(Builder $query): Builder
    {
        return $query->withQuantity()->having('batch_quantity', '>', 0.0005);
    }

    public function isSerial(): bool
    {
        return $this->kind === self::KIND_SERIAL;
    }

    public function hasExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->endOfDay()->isPast();
    }

    public function daysToExpiry(): ?int
    {
        return $this->expires_on === null
            ? null
            : (int) now()->startOfDay()->diffInDays($this->expires_on->startOfDay(), false);
    }

    public function label(): string
    {
        return $this->expires_on
            ? $this->code.' (exp. '.$this->expires_on->format('d/m/Y').')'
            : $this->code;
    }
}
