<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A membership a business sells — Gold, Silver, whatever it calls its tiers —
 * and the price and discount that come with it today.
 *
 * This is the price list, not a record of who bought what: changing a tier's
 * discount or price here is meant to change what the NEXT sale gets.
 * See vip_memberships for why an existing member's terms live elsewhere.
 */
class VipTier extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(VipMembership::class);
    }
}
