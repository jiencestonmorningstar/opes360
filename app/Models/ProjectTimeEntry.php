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
 * One person's hours against one project, on one day.
 *
 * Carries its own billability and rate rather than reading the project's
 * current ones, on the same reasoning payroll already uses: a payslip reads
 * the contract in force on its own date, because a rate that changes in March
 * must not silently restate what January cost.
 */
class ProjectTimeEntry extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'worked_on' => 'date',
            'hours' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'is_billable' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The service visit these hours were worked on, where they were.
     *
     * Service management logs its technicians' time here rather than keeping a
     * timesheet of its own: it is the same fact — one person, one day, so many
     * hours, at a rate, frozen once billed — and two tables would mean two
     * answers to "how many hours did she work in September".
     */
    public function serviceJob(): BelongsTo
    {
        return $this->belongsTo(ServiceJob::class, 'service_job_id');
    }

    public function scopeBillable(Builder $query): Builder
    {
        return $query->where('is_billable', true);
    }

    public function scopeUnlocked(Builder $query): Builder
    {
        return $query->whereNull('locked_at');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    protected static function booted(): void
    {
        /*
         * Once billed or a period closed, an entry is history rather than a
         * draft. Editing it after the fact would mean an invoice already sent
         * disagreeing with the timesheet underneath it — the same shape of
         * problem an issued sales document already guards against.
         */
        static::updating(function (self $entry) {
            if ($entry->getOriginal('locked_at') === null) {
                return;
            }

            $illegal = array_diff(array_keys($entry->getDirty()), ['locked_at', 'updated_at']);

            if ($illegal !== []) {
                throw new RuntimeException('This time entry is locked and cannot be edited.');
            }
        });

        static::deleting(function (self $entry) {
            if ($entry->locked_at !== null) {
                throw new RuntimeException('A locked time entry cannot be deleted.');
            }
        });
    }
}
