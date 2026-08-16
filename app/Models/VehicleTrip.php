<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One journey: out at one reading, back at another.
 *
 * The driver here is who was behind the wheel that day, which is not the same
 * question as the custodian on the asset. A van signed out to the workshop
 * foreman is still driven by whoever was free, and on the day something is
 * being investigated it is the second name that is wanted.
 */
class VehicleTrip extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'trip_date' => 'date',
            'start_odometer' => 'integer',
            'end_odometer' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /** Worked out, never stored: a third copy could disagree with the two readings. */
    public function distance(): int
    {
        return max(0, (int) $this->end_odometer - (int) $this->start_odometer);
    }

    /**
     * Date casts are midnight timestamps, so a range bounded on the raw dates
     * silently drops everything driven on the closing day — which is the day
     * somebody running a month-end report cares about most.
     */
    public function scopeBetweenDates(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('trip_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }
}
