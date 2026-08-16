<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * An appraisal of one person over one period.
 *
 * The record exists to be produced later — in a promotion argument, or in a
 * dismissal the labour inspector asks about. That is why acknowledgement
 * freezes the verdict: an employee's "I was told this" is worthless if the
 * "this" can still be edited afterwards.
 */
class PerformanceReview extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    public const CYCLES = [
        'annual' => 'Annual',
        'mid_year' => 'Mid-year',
        'quarterly' => 'Quarterly',
        'probation' => 'End of trial period',
    ];

    public const STATUSES = [
        'draft' => 'Draft',
        'shared' => 'Shared with the employee',
        'acknowledged' => 'Acknowledged',
    ];

    /** A five-point scale, because a business explaining a rating needs words, not a number. */
    public const RATINGS = [
        1 => 'Unsatisfactory',
        2 => 'Below expectations',
        3 => 'Meets expectations',
        4 => 'Exceeds expectations',
        5 => 'Outstanding',
    ];

    /** Fields the verdict is made of: frozen once the employee has acknowledged it. */
    protected const VERDICT = [
        'overall_rating', 'summary', 'strengths', 'improvements', 'goals',
        'period_starts_on', 'period_ends_on', 'cycle', 'reviewer_id', 'position_id',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_starts_on' => 'date',
            'period_ends_on' => 'date',
            'shared_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'overall_rating' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('period_starts_on')->orderByDesc('created_at');
    }

    public function ratingLabel(): ?string
    {
        return self::RATINGS[$this->overall_rating] ?? null;
    }

    public function isAcknowledged(): bool
    {
        return $this->status === 'acknowledged';
    }

    /**
     * Hand it to the employee.
     *
     * Refused without a rating: sharing an unrated appraisal invites an
     * acknowledgement of a verdict that was never actually reached, and the
     * rating could then never be added because acknowledgement freezes it.
     */
    public function share(): void
    {
        if ($this->overall_rating === null) {
            throw new RuntimeException('A review cannot be shared before it has been rated.');
        }

        $this->forceFill(['status' => 'shared', 'shared_at' => now()])->save();
    }

    public function acknowledge(?string $comment = null): void
    {
        if ($this->status !== 'shared') {
            throw new RuntimeException('Only a review the employee has been shown can be acknowledged.');
        }

        $this->forceFill([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
            'employee_comment' => $comment ?? $this->employee_comment,
        ])->save();
    }

    protected static function booted(): void
    {
        static::saving(function (self $review) {
            if (! array_key_exists((string) $review->cycle, self::CYCLES)) {
                throw new RuntimeException('Unknown review cycle: '.$review->cycle);
            }

            if ($review->overall_rating !== null && ! array_key_exists((int) $review->overall_rating, self::RATINGS)) {
                throw new RuntimeException('A rating must be between 1 and 5.');
            }

            if ($review->period_starts_on !== null && $review->period_ends_on !== null
                && $review->period_ends_on->lessThan($review->period_starts_on)) {
                throw new RuntimeException('A review period cannot end before it starts.');
            }

            /*
             * The verdict is frozen once acknowledged. share() and
             * acknowledge() both write through forceFill on the status
             * columns, which are not part of VERDICT, so the transitions
             * themselves pass — only a later rewrite of what was agreed is
             * refused. The employee's own comment stays editable on purpose.
             */
            if ($review->getOriginal('status') !== 'acknowledged') {
                return;
            }

            foreach (self::VERDICT as $field) {
                if ($review->isDirty($field)) {
                    throw new RuntimeException(
                        'An acknowledged review cannot be rewritten: '.$field.' was changed.'
                    );
                }
            }
        });
    }
}
