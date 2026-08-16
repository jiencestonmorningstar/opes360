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
 * A site the business keeps equipment at.
 *
 * Separate from `stock_locations`, which holds goods for sale. A warehouse
 * and a head office can share an address and still be different things: one
 * holds stock that will be sold, the other holds a generator that will not.
 */
class AssetLocation extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'asset_location_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
