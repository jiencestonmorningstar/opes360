<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One lettable door: an apartment, a shop front, a floor.
 *
 * Its status answers the only question a letting agent asks first — "is it
 * earning?" — and it is written by the tenancy service, not by a form, so it
 * can never disagree with who actually holds the lease.
 */
class PropertyUnit extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const STATUSES = [
        'vacant' => 'Vacant',
        'occupied' => 'Occupied',
        'unavailable' => 'Unavailable',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['target_rent' => 'decimal:2'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function tenancies(): HasMany
    {
        return $this->hasMany(Tenancy::class)->orderByDesc('moved_in_on');
    }

    /** Who holds it now, if anyone. */
    public function currentTenancy(): HasOne
    {
        return $this->hasOne(Tenancy::class)->where('status', 'active');
    }

    public function isVacant(): bool
    {
        return $this->status === 'vacant';
    }

    public function scopeVacant(Builder $query): Builder
    {
        return $query->where('status', 'vacant');
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'occupied' => ['label' => 'Occupied', 'tone' => 'positive'],
            'unavailable' => ['label' => 'Unavailable', 'tone' => 'muted'],
            default => ['label' => 'Vacant', 'tone' => 'warning'],
        };
    }
}
