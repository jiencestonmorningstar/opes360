<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The part of a van that a generator does not have.
 *
 * Not a vehicle record — a fixed asset is already the vehicle record. This is
 * the plate, the chassis number and the papers, hung off the asset one-to-one.
 * Everything else a business asks about a van — what it cost, where it is, who
 * has it, when it was last serviced — is asked of the asset, and there is only
 * ever one answer because there is only ever one row.
 */
class VehicleDetail extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const FUEL_TYPES = [
        'petrol' => 'Petrol',
        'diesel' => 'Diesel',
        'electric' => 'Electric',
        'hybrid' => 'Hybrid',
        'lpg' => 'LPG',
    ];

    /** The papers that stop a van, and what to call each of them. */
    public const PAPERS = [
        'insurance_expires_on' => 'Insurance',
        'roadworthy_expires_on' => 'Roadworthiness',
        'licence_expires_on' => 'Licence',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'insurance_expires_on' => 'date',
            'roadworthy_expires_on' => 'date',
            'licence_expires_on' => 'date',
            'tank_litres' => 'decimal:2',
            'year' => 'integer',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /** How a person names the van: the plate if it has one, otherwise the make. */
    public function label(): string
    {
        return $this->registration
            ?: trim("{$this->make} {$this->model}")
            ?: 'Vehicle';
    }

    public function fuelTypeLabel(): ?string
    {
        return $this->fuel_type === null
            ? null
            : (self::FUEL_TYPES[$this->fuel_type] ?? ucfirst((string) $this->fuel_type));
    }

    /**
     * Every paper it has, soonest first.
     *
     * @return array<int, array{kind: string, expires_on: Carbon, days: int, lapsed: bool}>
     */
    public function papers(): array
    {
        $today = Carbon::today();
        $found = [];

        foreach (self::PAPERS as $column => $kind) {
            $date = $this->{$column};

            if ($date === null) {
                continue;
            }

            $found[] = [
                'kind' => $kind,
                'expires_on' => $date,
                // Negative once it has run out, which reads correctly in a
                // sort: the lapsed ones come first, most lapsed of all.
                'days' => (int) $today->diffInDays($date, false),
                'lapsed' => $date->lt($today),
            ];
        }

        usort($found, fn ($a, $b) => $a['days'] <=> $b['days']);

        return $found;
    }
}
