<?php

namespace App\Livewire\Hr;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

/**
 * Who was in over a period, and for how long.
 *
 * Two abilities, kept apart on the screen as well as in the gate: seeing that a
 * team turned up is a supervisor's business, while writing the hours somebody
 * worked is the input to an argument about a wage. A holder of
 * `attendance.view` gets the table and no form at all.
 *
 * The hours shown here are evidence, not money. Payroll never reads this table
 * — see the model — so nothing on this screen may be dressed up as a deduction
 * or a payable amount.
 */
class Attendance extends Component
{
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $employeeFilter = '';

    // ── Entry form ──────────────────────────────────────────────────────
    public bool $recording = false;

    public string $employeeId = '';

    public string $workedOn = '';

    public string $status = 'present';

    public string $checkedInAt = '';

    public string $checkedOutAt = '';

    public string $minutesWorked = '';

    public string $notes = '';

    public function mount(): void
    {
        Gate::authorize('attendance.view');

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->endOfMonth()->toDateString();
        $this->workedOn = now()->toDateString();
    }

    public function startRecording(?string $employeeId = null, ?string $workedOn = null): void
    {
        Gate::authorize('attendance.record');

        $this->reset(['checkedInAt', 'checkedOutAt', 'minutesWorked', 'notes']);
        $this->resetValidation();

        $this->employeeId = (string) ($employeeId ?? $this->employeeId);
        $this->workedOn = $workedOn ?? now()->toDateString();
        $this->status = 'present';
        $this->recording = true;
    }

    /**
     * Load a day that already has a row, so the form shows what is being
     * replaced. Editing an existing day and entering a fresh one are the same
     * act here — the day either has a record or it does not.
     */
    public function edit(string $id): void
    {
        Gate::authorize('attendance.record');

        $record = AttendanceRecord::findOrFail($id);

        $this->resetValidation();
        $this->employeeId = $record->employee_id;
        $this->workedOn = $record->worked_on->toDateString();
        $this->status = $record->status;
        $this->checkedInAt = $record->checked_in_at?->format('H:i') ?? '';
        $this->checkedOutAt = $record->checked_out_at?->format('H:i') ?? '';
        $this->minutesWorked = $record->minutes_worked === null ? '' : (string) $record->minutes_worked;
        $this->notes = (string) $record->notes;
        $this->recording = true;
    }

    public function cancel(): void
    {
        $this->recording = false;
        $this->resetValidation();
    }

    /**
     * One row per person per day — a second entry for the same day replaces
     * the first rather than joining it.
     *
     * The database refuses the duplicate anyway, but a screen that let a user
     * hit that error would be a screen that had already lost the correction
     * they were making. And an appended duplicate would double every total
     * built on this table with no way for a later reader to tell which of the
     * two rows was real.
     */
    public function save(): void
    {
        Gate::authorize('attendance.record');

        $this->validate([
            'employeeId' => ['required', 'string'],
            'workedOn' => ['required', 'date'],
            'status' => ['required', 'in:'.implode(',', array_keys(AttendanceRecord::STATUSES))],
            'checkedInAt' => ['nullable', 'date_format:H:i'],
            'checkedOutAt' => ['nullable', 'date_format:H:i'],
            'minutesWorked' => ['nullable', 'numeric', 'min:0', 'max:1440'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [
            'employeeId.required' => 'Whose day is this?',
        ]);

        $employee = Employee::findOrFail($this->employeeId);
        $day = Carbon::parse($this->workedOn)->startOfDay();

        /*
         * The day is found with whereDate rather than matched on the raw
         * value. The `date` cast writes a midnight timestamp, so comparing
         * against the bare date string finds nothing on SQLite and the
         * correction is written as a second row for the same day — which is
         * exactly the doubling the unique key exists to stop, arriving as a
         * constraint error in the user's face instead of as a saved edit.
         */
        $existing = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereDate('worked_on', $day->toDateString())
            ->first();

        $attributes = [
            'status' => $this->status,
            'source' => 'manual',
            'checked_in_at' => $this->at($day, $this->checkedInAt),
            'checked_out_at' => $this->at($day, $this->checkedOutAt),
            /*
             * Blanked when it was not typed, so the model derives it again
             * from the corrected times. Carrying the old figure over would
             * leave a fixed clock-out still showing the duration it was
             * fixing.
             */
            'minutes_worked' => $this->minutesWorked === '' ? null : (int) $this->minutesWorked,
            'notes' => $this->notes ?: null,
            'recorded_by' => auth()->id(),
        ];

        try {
            $existing !== null
                ? $existing->update($attributes)
                : AttendanceRecord::create($attributes + [
                    'employee_id' => $employee->id,
                    'worked_on' => $day,
                ]);
        } catch (RuntimeException $e) {
            $this->addError('checkedOutAt', $e->getMessage());

            return;
        }

        $this->recording = false;
        $this->reset(['checkedInAt', 'checkedOutAt', 'minutesWorked', 'notes']);

        $this->dispatch('toast', message: $employee->name().' — '.$day->format('j M').' recorded.');
    }

    public function remove(string $id): void
    {
        Gate::authorize('attendance.record');

        AttendanceRecord::findOrFail($id)->delete();

        $this->dispatch('toast', message: 'Day removed.');
    }

    /** A clock time is meaningless without the day it belongs to. */
    protected function at(Carbon $day, string $time): ?Carbon
    {
        return $time === '' ? null : Carbon::parse($day->toDateString().' '.$time);
    }

    public function render(): View
    {
        $records = AttendanceRecord::query()
            ->forPeriod($this->from ?: now()->startOfMonth()->toDateString(), $this->to ?: now()->endOfMonth()->toDateString())
            ->when($this->employeeFilter !== '', fn ($query) => $query->where('employee_id', $this->employeeFilter))
            ->with('employee')
            ->orderByDesc('worked_on')
            ->get();

        /*
         * Per person: days recorded, days actually worked and total minutes.
         * Minutes rather than money, deliberately — see the class note.
         */
        $totals = $records
            ->groupBy('employee_id')
            ->map(fn ($rows) => [
                'employee' => $rows->first()->employee,
                'days' => $rows->count(),
                'present' => $rows->whereIn('status', ['present', 'late', 'half_day', 'remote'])->count(),
                'absent' => $rows->where('status', 'absent')->count(),
                'minutes' => (int) $rows->sum('minutes_worked'),
            ])
            ->sortBy(fn ($row) => $row['employee']?->name())
            ->values();

        return view('livewire.hr.attendance', [
            'records' => $records,
            'totals' => $totals,
            // Days clocked in with no clock-out: somebody forgot, and the
            // hours for that day are missing rather than zero.
            'open' => $records->filter->isOpen(),
            'people' => Employee::query()
                ->where('status', 'active')
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(),
            'statuses' => AttendanceRecord::STATUSES,
        ])->layout('components.layouts.app', ['title' => 'Attendance', 'active' => 'team']);
    }
}
