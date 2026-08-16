<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\BusinessHours;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What the business has promised its customers, and when it is open to keep it.
 *
 * The working calendar belongs here rather than in company settings because it
 * is a commercial term: the same business can sell a 24/7 contract to a
 * hospital and 08:00–17:00 to a corner shop, and both are true at once.
 */
class ServiceSlaPolicy extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'business_hours' => 'array',
            'holidays' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ServiceSlaTarget::class, 'sla_policy_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(ServiceTicket::class, 'sla_policy_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function default(): ?self
    {
        return static::query()->active()->where('is_default', true)->first();
    }

    public function targetFor(string $priority): ?ServiceSlaTarget
    {
        return $this->targets->firstWhere('priority', $priority);
    }

    /** The calendar this policy counts on. */
    public function hours(): BusinessHours
    {
        if ($this->clock === 'calendar') {
            return BusinessHours::continuous($this->timezone ?: 'UTC');
        }

        return new BusinessHours(
            $this->business_hours ?? [],
            $this->timezone ?: 'UTC',
            $this->holidays ?? [],
        );
    }

    protected static function booted(): void
    {
        /*
         * One default per company, kept true by demoting the others rather
         * than by a unique index. MySQL has no filtered index, so a unique on
         * (company_id, is_default) would forbid a second *non*-default policy
         * — the opposite of the rule intended.
         */
        static::saved(function (self $policy) {
            if (! $policy->is_default) {
                return;
            }

            static::query()
                ->where('company_id', $policy->company_id)
                ->whereKeyNot($policy->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }
}
