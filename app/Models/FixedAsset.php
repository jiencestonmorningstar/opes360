<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Something the business owns and will keep using.
 *
 * The distinction from an expense is not the size of the number, it is whether
 * the thing goes on being useful after the month it was bought in. A van does;
 * a tank of fuel does not. Putting the van through the expense screen makes one
 * month look catastrophic and every month after it look better than it is.
 */
class FixedAsset extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const METHODS = [
        'straight_line' => 'Straight line',
        'declining' => 'Declining balance',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'acquired_on' => 'date',
            'in_service_on' => 'date',
            'disposed_on' => 'date',
            'cost' => 'decimal:2',
            'residual_value' => 'decimal:2',
            'opening_accumulated' => 'decimal:2',
            'accumulated_depreciation' => 'decimal:2',
            'disposal_proceeds' => 'decimal:2',
            'declining_rate' => 'decimal:4',
            'useful_life_months' => 'integer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    public function depreciationAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'depreciation_account_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    /**
     * Deliberately NOT `location()`: this model has a `location` string column,
     * and an attribute of the same name shadows the relation — `$asset->location`
     * would keep returning the free text and never load the site. The same trap
     * caught `Employee::departmentRecord()`; the naming follows that precedent.
     */
    public function locationRecord(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'asset_location_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class)->latest('transferred_on');
    }

    public function maintenance(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
    }

    /**
     * Faults reported against this machine by the service desk.
     *
     * Separate from `maintenance()` on purpose: maintenance is what the
     * business planned to do to it, a ticket is what somebody says went wrong.
     * Where a visit turns out to be the planned service after all, the job
     * links to the maintenance record rather than copying it.
     */
    public function serviceTickets(): HasMany
    {
        return $this->hasMany(ServiceTicket::class)->latest('opened_at');
    }

    /**
     * The vehicle side of an asset that happens to be driven.
     *
     * Named `vehicle` and not `registration` or `details` for the reason set
     * out above `locationRecord`: the plate lives on the other model as a
     * `registration` column, and a relation sharing a column's name is
     * shadowed by it without a word of complaint.
     */
    public function vehicle(): HasOne
    {
        return $this->hasOne(VehicleDetail::class, 'fixed_asset_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(VehicleTrip::class, 'fixed_asset_id')->latest('trip_date');
    }

    public function fuelLogs(): HasMany
    {
        return $this->hasMany(FuelLog::class, 'fixed_asset_id')->latest('filled_on');
    }

    /**
     * Whether this is something the fleet screens have anything to say about.
     *
     * Read off the vehicle record rather than the category, because a business
     * may well file a motorbike or a generator trailer under something else and
     * still want a milometer against it. Having the details is the answer.
     */
    public function isVehicle(): bool
    {
        return $this->vehicle !== null;
    }

    /** Where it is, preferring the real site over whatever was typed. */
    public function locationName(): ?string
    {
        return $this->locationRecord?->name ?? ($this->location ?: null);
    }

    public function categoryLabel(): string
    {
        return ChartOfAccounts::ASSET_CATEGORIES[$this->category][5] ?? ucfirst((string) $this->category);
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? ucfirst((string) $this->method);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isDisposed(): bool
    {
        return in_array($this->status, ['disposed', 'written_off'], true);
    }

    /**
     * Land is not depreciated, and neither is anything a business has chosen to
     * give no useful life. Both are read off the record rather than special-
     * cased by category name.
     */
    public function isDepreciable(): bool
    {
        return $this->depreciation_account_id !== null && $this->useful_life_months > 0;
    }

    /** Cost less everything written off it so far. */
    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
    }

    /** The floor depreciation may not go below. */
    public function depreciableFloor(): float
    {
        return round((float) $this->residual_value, 2);
    }

    /** What is left to write off. */
    public function remainingToDepreciate(): float
    {
        return max(0, round($this->bookValue() - $this->depreciableFloor(), 2));
    }

    /** When depreciation starts: the day it went into use, or the day it was bought. */
    public function startsDepreciatingOn(): Carbon
    {
        return ($this->in_service_on ?? $this->acquired_on)->copy()->startOfMonth();
    }

    /**
     * The month after which there is nothing left to charge — for a schedule
     * a business can look at, not for the arithmetic, which stops when the
     * floor is reached whatever the calendar says.
     */
    public function fullyDepreciatedOn(): ?Carbon
    {
        if (! $this->isDepreciable()) {
            return null;
        }

        return $this->startsDepreciatingOn()->addMonths($this->useful_life_months)->subDay();
    }

    public function isFullyDepreciated(): bool
    {
        return $this->isDepreciable() && $this->remainingToDepreciate() <= 0.005;
    }
}
