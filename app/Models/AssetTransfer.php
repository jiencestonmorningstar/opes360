<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of one asset: to a new site, into new hands, or both.
 *
 * Written once and never edited. The asset carries where it is now; this
 * carries how it got there, and that is the record a business needs when
 * something cannot be found.
 */
class AssetTransfer extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['transferred_on' => 'date'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'to_location_id');
    }

    public function fromCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_custodian_id');
    }

    public function toCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_custodian_id');
    }

    public function movedSite(): bool
    {
        return $this->from_location_id !== $this->to_location_id;
    }

    public function changedHands(): bool
    {
        return $this->from_custodian_id !== $this->to_custodian_id;
    }
}
