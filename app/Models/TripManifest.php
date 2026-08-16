<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One vehicle, one driver, one departure — and everything aboard.
 *
 * The vehicle is a FixedAsset, because a truck IS an asset: bought,
 * depreciated, serviced and fuelled by the machinery the register already
 * has. The manifest borrows the van; it does not keep a second copy of it.
 */
class TripManifest extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;

    public const STATUSES = [
        'open' => 'Open',
        'dispatched' => 'Dispatched',
        'closed' => 'Closed',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'departs_on' => 'date',
        ];
    }

    /** The vehicle — an asset off the register, with its VehicleDetail beside it. */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    /** The fleet-log journey this manifest became when it closed, if readings were taken. */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(VehicleTrip::class, 'vehicle_trip_id');
    }

    public function shipments(): BelongsToMany
    {
        return $this->belongsToMany(Shipment::class, 'trip_manifest_shipments')
            ->using(TripManifestShipment::class)
            ->withTimestamps();
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isDispatched(): bool
    {
        return $this->status === 'dispatched';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst((string) $this->status);
    }
}
