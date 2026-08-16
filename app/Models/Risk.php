<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Something that could go wrong, scored twice: as it stands untreated, and as
 * it stands with the controls the business actually has.
 *
 * Keeping those two numbers apart is the only thing that makes a register
 * useful. One number means every risk drifts towards "handled" and the list
 * stops being a reason to do anything.
 */
class Risk extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const CATEGORIES = [
        'operational' => 'Operational',
        'financial' => 'Financial',
        'regulatory' => 'Regulatory',
        'strategic' => 'Strategic',
        'people' => 'People',
        'technology' => 'Technology',
        'external' => 'External',
    ];

    public const TREATMENTS = [
        'accept' => 'Accept',
        'mitigate' => 'Mitigate',
        'transfer' => 'Transfer',
        'avoid' => 'Avoid',
    ];

    /**
     * The 5×5 bands, stated once.
     *
     * Boundaries live here rather than in a view because two screens
     * disagreeing about where "high" starts is a disagreement about which
     * risks get attention.
     */
    public const BANDS = [
        4 => 'low',
        9 => 'moderate',
        14 => 'high',
        25 => 'severe',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'likelihood' => 'integer',
            'impact' => 'integer',
            'residual_likelihood' => 'integer',
            'residual_impact' => 'integer',
            'identified_on' => 'date',
            'last_reviewed_on' => 'date',
            'next_review_on' => 'date',
            'closed_on' => 'date',
            'review_interval_months' => 'integer',
        ];
    }

    public function controls(): HasMany
    {
        return $this->hasMany(RiskControl::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Computed, never stored — see the migration for why. */
    public function inherentScore(): int
    {
        return (int) $this->likelihood * (int) $this->impact;
    }

    /**
     * Falls back to the inherent score until somebody has actually
     * reassessed. A control existing on paper lowers nothing: assuming
     * otherwise produces a comfortable number no human ever chose.
     */
    public function residualScore(): int
    {
        $likelihood = $this->residual_likelihood ?? $this->likelihood;
        $impact = $this->residual_impact ?? $this->impact;

        return (int) $likelihood * (int) $impact;
    }

    public function hasBeenReassessed(): bool
    {
        return $this->residual_likelihood !== null || $this->residual_impact !== null;
    }

    public function inherentBand(): string
    {
        return self::band($this->inherentScore());
    }

    public function residualBand(): string
    {
        return self::band($this->residualScore());
    }

    public static function band(int $score): string
    {
        foreach (self::BANDS as $ceiling => $label) {
            if ($score <= $ceiling) {
                return $label;
            }
        }

        return 'severe';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isDueForReview(): bool
    {
        return ! $this->isClosed()
            && $this->next_review_on !== null
            && $this->next_review_on->startOfDay()->lessThanOrEqualTo(Carbon::today());
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', 'closed');
    }

    /**
     * Ranked by what would hurt most untreated.
     *
     * Computed in SQL from the two columns rather than read from a stored
     * score, so the ordering cannot be stale. Untreated rather than residual
     * on purpose: a register sorted by residual puts the risks somebody has
     * declared handled at the bottom, which is exactly where an optimistic
     * reassessment would like them to be.
     */
    public function scopeMostSevereFirst(Builder $query): Builder
    {
        return $query->orderByRaw('(likelihood * impact) desc')->orderBy('created_at');
    }

    public function scopeDueForReviewBy(Builder $query, Carbon $date): Builder
    {
        return $query->open()
            ->whereNotNull('next_review_on')
            ->where('next_review_on', '<=', $date->copy()->endOfDay());
    }
}
