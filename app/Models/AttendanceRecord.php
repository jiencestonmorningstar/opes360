<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One person, one day: who was in and when.
 *
 * ── Why payroll does not read this ───────────────────────────────────────
 *
 * A payroll run reads the employment contract in force on the payslip's own
 * date, so that June's payslip reproduces June forever. Attendance is edited
 * long after the fact — a supervisor correcting last month's timesheet is
 * normal. If a calculation reached into this table, that correction would
 * silently rewrite a payslip that has already been paid and declared to the
 * CNPS. Any deduction for absence must be an explicit salary component
 * entered onto the run, where somebody can see it.
 */
class AttendanceRecord extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const STATUSES = [
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'half_day' => 'Half day',
        'leave' => 'On leave',
        'holiday' => 'Public holiday',
        'remote' => 'Working remotely',
    ];

    public const SOURCES = [
        'manual' => 'Entered by hand',
        'import' => 'Imported',
        'device' => 'Clocking device',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'worked_on' => 'date',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'minutes_worked' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * Days inside a range, inclusive at both ends — how a month is read.
     *
     * Bounded on whole days rather than on the raw strings: the `date` cast
     * stores `worked_on` as a midnight timestamp, so a plain between against
     * '2026-09-30' silently drops the last day of the month — the one a
     * payroll cut-off is most likely to care about.
     */
    public function scopeForPeriod(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('worked_on', [
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
        ]);
    }

    public function isOpen(): bool
    {
        return $this->checked_in_at !== null && $this->checked_out_at === null;
    }

    public function hours(): ?float
    {
        return $this->minutes_worked === null ? null : round($this->minutes_worked / 60, 2);
    }

    protected static function booted(): void
    {
        static::saving(function (self $record) {
            if (! array_key_exists((string) $record->status, self::STATUSES)) {
                throw new RuntimeException('Unknown attendance status: '.$record->status);
            }

            if ($record->checked_in_at === null || $record->checked_out_at === null) {
                return;
            }

            /*
             * A clock-out before the clock-in is a data-entry slip, and left
             * alone it yields negative minutes that subtract from the month's
             * total instead of merely being wrong on one row.
             */
            if ($record->checked_out_at->lessThan($record->checked_in_at)) {
                throw new RuntimeException('An attendance record cannot end before it starts.');
            }

            // Only derived when it was not stated. A device import carries its
            // own duration, and recomputing it here would quietly overwrite
            // the authoritative figure with one built from rounded times.
            if ($record->minutes_worked === null) {
                $record->minutes_worked = (int) $record->checked_in_at->diffInMinutes($record->checked_out_at);
            }
        });
    }
}
