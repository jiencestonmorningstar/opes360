<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A standing arrangement to bill a customer on a rhythm.
 *
 * The lines are the schedule's own copy, not a link to a template invoice: the
 * agreement has to keep billing the agreed amount even after somebody edits or
 * voids the document it was first modelled on.
 */
class RecurringInvoice extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use HasUlids;

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const FINISHED = 'finished';

    /** Steps a period can take. Anything else is not a billing rhythm. */
    public const FREQUENCIES = [
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly' => 'Yearly',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'discount_percent' => 'decimal:2',
            'interval' => 'integer',
            'occurrences' => 'integer',
            'max_occurrences' => 'integer',
            'payment_terms_days' => 'integer',
            'auto_issue' => 'boolean',
            'starts_on' => 'date',
            'next_run_on' => 'date',
            'ends_on' => 'date',
            'last_run_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lastDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'last_document_id');
    }

    public function scopeDue(Builder $query, ?Carbon $on = null): Builder
    {
        return $query
            ->where('status', self::ACTIVE)
            ->whereDate('next_run_on', '<=', ($on ?? now())->toDateString());
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /**
     * The date after this one.
     *
     * NoOverflow for the same reason the VIP term uses it: a monthly schedule
     * starting 31 January would otherwise jump to 3 March, skipping February
     * entirely and drifting the billing day forward for good. Clamping to the
     * last day of the target month is what "monthly" is understood to mean.
     */
    public function advanceFrom(Carbon $from): Carbon
    {
        $step = max(1, $this->interval);

        return match ($this->frequency) {
            'weekly' => $from->copy()->addWeeks($step),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3 * $step),
            'yearly' => $from->copy()->addYearsNoOverflow($step),
            default => $from->copy()->addMonthsNoOverflow($step),
        };
    }

    /**
     * Whether this schedule has run its course.
     *
     * Checked after each generation rather than only on a date, because a
     * schedule can be bounded by an end date, by a count, or by neither.
     */
    public function hasFinished(?Carbon $nextRun = null): bool
    {
        if ($this->max_occurrences !== null && $this->occurrences >= $this->max_occurrences) {
            return true;
        }

        $nextRun ??= $this->next_run_on;

        return $this->ends_on !== null && $nextRun !== null && $nextRun->gt($this->ends_on);
    }

    /** The schedule in words, for a list row. */
    public function rhythm(): string
    {
        $label = self::FREQUENCIES[$this->frequency] ?? 'Monthly';

        if ($this->interval <= 1) {
            return $label;
        }

        return match ($this->frequency) {
            'weekly' => "Every {$this->interval} weeks",
            'quarterly' => "Every {$this->interval} quarters",
            'yearly' => "Every {$this->interval} years",
            default => "Every {$this->interval} months",
        };
    }
}
