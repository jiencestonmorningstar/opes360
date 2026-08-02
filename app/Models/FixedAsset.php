<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\Accounting\ChartOfAccounts;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
