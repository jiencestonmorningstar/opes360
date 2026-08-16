<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * One shipment aboard one manifest.
 *
 * A model only so the pivot's ULID key is minted on attach — plain attach()
 * writes no id. The business rule (one open manifest per shipment) lives in
 * the Dispatch service, inside its transaction, not here.
 */
class TripManifestShipment extends Pivot
{
    use BelongsToCompany;
    use HasUlids;

    protected $table = 'trip_manifest_shipments';

    public $incrementing = false;
}
