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
 * A standing statutory duty — the TVA return, the CNPS declaration, the
 * licence that has to be renewed every year.
 *
 * The whole point of the register is that none of these lives in one person's
 * head. This row is the answer to "what is next, and who is accountable"; the
 * filings underneath it are the proof that the previous ones were met.
 */
class ComplianceObligation extends Model
{
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const CATEGORIES = [
        'tax' => 'Tax',
        'social' => 'Social contributions',
        'licence' => 'Licence or permit',
        'insurance' => 'Insurance',
        'reporting' => 'Statutory reporting',
        'other' => 'Other',
    ];

    /** See the migration: the one genuinely hard decision in this table. */
    public const SCHEDULE_BASES = [
        'due' => 'From the deadline',
        'completion' => 'From the date it was done',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'next_due_on' => 'date',
            'requires_approval' => 'boolean',
            'is_active' => 'boolean',
            'lead_days' => 'integer',
            'interval_months' => 'integer',
        ];
    }

    public function filings(): HasMany
    {
        return $this->hasMany(ComplianceFiling::class)->latest('due_on');
    }

    /** The one being worked on now, if any. */
    public function openFiling(): ?ComplianceFiling
    {
        return $this->filings()->whereIn('status', ['draft', 'submitted'])->first();
    }

    public function hasOpenFiling(): bool
    {
        return $this->openFiling() !== null;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function repeats(): bool
    {
        return $this->interval_months !== null && $this->interval_months > 0;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst((string) $this->category);
    }

    public function isOverdue(): bool
    {
        return $this->is_active
            && $this->next_due_on !== null
            && $this->next_due_on->startOfDay()->isBefore(Carbon::today());
    }

    /**
     * Close enough to start worrying, on this obligation's own terms.
     *
     * A deadline already missed is not "due soon" — it belongs on the overdue
     * list. Mixing the two makes a list people stop opening, which is the one
     * failure this module cannot survive.
     */
    public function isDueSoon(): bool
    {
        if (! $this->is_active || $this->next_due_on === null || $this->isOverdue()) {
            return false;
        }

        return $this->next_due_on->startOfDay()
            ->lessThanOrEqualTo(Carbon::today()->addDays((int) $this->lead_days));
    }

    /**
     * Where the following deadline falls, given this one was met on that day.
     *
     * Kept on the model rather than in the service so the answer can be shown
     * on a screen before anybody commits to it — "file this and the next one
     * is due in September" is the sentence that stops a mistake.
     */
    public function nextDueAfter(Carbon $dueOn, Carbon $completedOn): ?Carbon
    {
        if (! $this->repeats()) {
            return null;
        }

        $basis = $this->schedule_basis === 'completion' ? $completedOn : $dueOn;

        return $basis->copy()->startOfDay()->addMonths((int) $this->interval_months);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Deadlines inside a window, both ends included.
     *
     * The bounds are widened to the whole day on purpose. `next_due_on` is a
     * date cast, so a Carbon carrying a time compares against midnight and
     * silently drops everything falling on the last day of the range — which,
     * on a compliance calendar, is the deadline itself.
     */
    public function scopeDueBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query
            ->whereNotNull('next_due_on')
            ->whereBetween('next_due_on', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('next_due_on')
            ->where('next_due_on', '<', Carbon::today()->startOfDay());
    }
}
