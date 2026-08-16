<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A service, repair or inspection — due, or done.
 *
 * The same row covers both states. A service booked for next month and the
 * same service once carried out are one event at two moments; keeping them
 * apart would mean matching up two records of the same visit afterwards.
 */
class AssetMaintenance extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $table = 'asset_maintenance';

    public const KINDS = [
        'service' => 'Service',
        'repair' => 'Repair',
        'inspection' => 'Inspection',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_on' => 'date',
            'cost' => 'decimal:2',
            'due_at_odometer' => 'integer',
            'completed_at_odometer' => 'integer',
            'interval_km' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** The bill, when the work was paid for. Never a second copy of it. */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function isDone(): bool
    {
        return $this->completed_on !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone()
            && $this->due_on !== null
            && $this->due_on->isPast();
    }

    /**
     * Whether the van has now driven far enough for this to be due.
     *
     * Deliberately separate from `isOverdue()` rather than folded into it. The
     * odometer is not on the row — it is worked out from what has been driven
     * and filled — so answering this needs a reading passed in, and a method
     * that quietly returned false whenever nobody had one would be worse than
     * one that asks for it.
     *
     * A null reading is "not known", which is not the same as "not yet due"
     * and is never treated as reassurance.
     */
    public function isDueAtDistance(?int $odometer): bool
    {
        return ! $this->isDone()
            && $this->due_at_odometer !== null
            && $odometer !== null
            && $odometer >= (int) $this->due_at_odometer;
    }

    /** Whether this visit falls due on distance at all. */
    public function isScheduledByDistance(): bool
    {
        return $this->due_at_odometer !== null || $this->interval_km !== null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst((string) $this->kind);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNull('completed_on');
    }

    public function scopeDueBy(Builder $query, Carbon $date): Builder
    {
        return $query->outstanding()->whereNotNull('due_on')->whereDate('due_on', '<=', $date);
    }
}
