<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\EmitsDomainEvents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A piece of chargeable or internal work with a client, a budget and a team.
 *
 * Points at the existing `contacts` table for its client rather than keeping
 * one of its own — CRM already owns customers, and a second customer list is
 * exactly what the master brief forbids.
 */
class Project extends Model
{
    use Approvable;
    use BelongsToCompany;
    use EmitsDomainEvents;
    use HasUlids;
    use SoftDeletes;

    public const STATUSES = [
        'planning' => 'Planning',
        'active' => 'Active',
        'on_hold' => 'On hold',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'closed_on' => 'date',
            'budget' => 'decimal:2',
            'default_hourly_rate' => 'decimal:2',
            'is_billable' => 'boolean',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function milestones(): HasMany
    {
        return $this->hasMany(ProjectMilestone::class)->orderBy('sort_order');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('sort_order');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(ProjectTimeEntry::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeBillable(Builder $query): Builder
    {
        return $query->where('is_billable', true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this->status, ['completed', 'cancelled'], true);
    }

    /**
     * Hours logged that have not yet been billed or locked into a closed
     * period. What a draft invoice for this project would actually contain.
     */
    public function unbilledHours(): float
    {
        return (float) $this->timeEntries()
            ->billable()
            ->whereNull('locked_at')
            ->sum('hours');
    }

    /**
     * Cost so far: logged time at its own rate, plus every project expense.
     *
     * Each time entry carries its own rate rather than the project's current
     * one — a rate that changes in March must not restate what January cost.
     */
    public function costToDate(): float
    {
        $labour = (float) $this->timeEntries()
            ->selectRaw('COALESCE(SUM(hours * COALESCE(hourly_rate, 0)), 0) as total')
            ->value('total');

        $expenses = (float) $this->expenses()->sum('total');

        return $labour + $expenses;
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'active' => ['label' => 'Active', 'tone' => 'positive'],
            'completed' => ['label' => 'Completed', 'tone' => 'positive'],
            'on_hold' => ['label' => 'On hold', 'tone' => 'warning'],
            'cancelled' => ['label' => 'Cancelled', 'tone' => 'muted'],
            default => ['label' => 'Planning', 'tone' => 'neutral'],
        };
    }

    /** What an automation rule may write to a project. */
    public function automatableFields(): array
    {
        return ['status', 'closed_on'];
    }

    protected function workflowCreatorColumn(): string
    {
        return 'created_by';
    }
}
