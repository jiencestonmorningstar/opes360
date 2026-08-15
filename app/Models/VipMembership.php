<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One customer's membership, sold under a tier's terms at the time.
 *
 * `tier_name`, `discount_percent` and `price_paid` are copied rather than
 * read through `tier()` — see the migration for why. This record answers
 * "what was this member sold", not "what does the tier currently cost".
 */
class VipMembership extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    public const ACTIVE = 'active';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'price_paid' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(VipTier::class, 'vip_tier_id');
    }

    public function verificationToken(): BelongsTo
    {
        return $this->belongsTo(VerificationToken::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Live right now, by the dates, not just by the status column.
     *
     * The status is maintained by a nightly sweep (see the later expiry-sweep
     * task) and is a convenience for listing and filtering, not the source of
     * truth. An invoice raised the day after a membership lapses must get no
     * discount even if the sweep has not run yet tonight — the sweep tidies
     * state, it does not enforce the rule.
     */
    public function isActive(): bool
    {
        if ($this->status !== self::ACTIVE) {
            return false;
        }

        $today = now()->startOfDay();

        return $today->gte($this->starts_on) && $today->lte($this->ends_on);
    }

    public function effectiveDiscount(): float
    {
        return $this->isActive() ? (float) $this->discount_percent : 0.0;
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE)
            ->whereDate('starts_on', '<=', now())
            ->whereDate('ends_on', '>=', now());
    }
}
