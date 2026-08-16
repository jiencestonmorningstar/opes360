<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tankful: litres in, and what the clock read at the pump.
 *
 * It carries no amount. The fill was paid for and there is an expense saying
 * so; this points at it. A figure kept here as well would drift from the one
 * in the books the first time either was corrected, and the month's fuel bill
 * would depend on which screen somebody happened to open — the same reasoning
 * that keeps servicing costs on the expense rather than on the job.
 */
class FuelLog extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'filled_on' => 'date',
            'litres' => 'decimal:2',
            'odometer' => 'integer',
            'is_full_tank' => 'boolean',
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

    /** The bill for the fill. Never a second copy of it. */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    /** Read off the bill each time, so the two can never disagree. */
    public function cost(): ?float
    {
        return $this->expense === null ? null : round((float) $this->expense->total, 2);
    }

    /** What a litre worked out at — the number that shows a pump overcharging. */
    public function pricePerLitre(): ?float
    {
        $cost = $this->cost();
        $litres = (float) $this->litres;

        return ($cost === null || $litres <= 0) ? null : round($cost / $litres, 2);
    }
}
