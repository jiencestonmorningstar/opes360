<?php

namespace Tests\Feature\Hr;

use App\Models\AttendanceRecord;
use Illuminate\Database\QueryException;
use RuntimeException;

class AttendanceTest extends HrTestCase
{
    public function test_a_day_is_recorded_against_an_employee(): void
    {
        $employee = $this->employee();
        $record = $this->attendance($employee);

        $this->assertTrue($record->employee->is($employee));
        $this->assertSame($this->company->id, $record->company_id);
        $this->assertTrue($employee->attendanceRecords->first()->is($record));
    }

    /**
     * One row per person per day, enforced by the database.
     *
     * Two rows for the same day is not a harmless duplicate: every total built
     * on this table — days present in a month, hours worked — silently doubles
     * for that person, and nothing in the reading code can tell which row is
     * the real one. A re-import must overwrite, not append.
     */
    public function test_an_employee_cannot_have_two_records_for_one_day(): void
    {
        $employee = $this->employee();
        $this->attendance($employee, ['worked_on' => '2026-09-10']);

        $this->expectException(QueryException::class);

        $this->attendance($employee, ['worked_on' => '2026-09-10']);
    }

    public function test_two_employees_share_a_day_without_colliding(): void
    {
        $this->attendance($this->employee(), ['worked_on' => '2026-09-10']);
        $this->attendance($this->employee(['first_name' => 'Paul']), ['worked_on' => '2026-09-10']);

        $this->assertCount(2, AttendanceRecord::all());
    }

    public function test_worked_minutes_are_derived_from_the_two_clock_times(): void
    {
        $record = $this->attendance($this->employee(), [
            'checked_in_at' => '2026-09-10 08:00:00',
            'checked_out_at' => '2026-09-10 16:30:00',
        ]);

        $this->assertSame(510, $record->fresh()->minutes_worked);
        $this->assertSame(8.5, $record->fresh()->hours());
    }

    /**
     * A clock-out before the clock-in is a data-entry slip, and left alone it
     * produces negative minutes that subtract from a month's total instead of
     * merely being wrong on one row.
     */
    public function test_clocking_out_before_clocking_in_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->attendance($this->employee(), [
            'checked_in_at' => '2026-09-10 16:00:00',
            'checked_out_at' => '2026-09-10 08:00:00',
        ]);
    }

    public function test_a_still_open_shift_has_no_worked_minutes(): void
    {
        $record = $this->attendance($this->employee(), [
            'checked_in_at' => '2026-09-10 08:00:00',
        ]);

        $this->assertNull($record->fresh()->minutes_worked);
        $this->assertTrue($record->fresh()->isOpen());
    }

    public function test_an_explicitly_recorded_duration_is_not_overwritten(): void
    {
        // Imports from a clocking device often carry a duration and no times.
        $record = $this->attendance($this->employee(), [
            'minutes_worked' => 420,
            'source' => 'import',
        ]);

        $this->assertSame(420, $record->fresh()->minutes_worked);
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->attendance($this->employee(), ['status' => 'maybe']);
    }

    public function test_absences_are_countable_for_a_period(): void
    {
        $employee = $this->employee();

        $this->attendance($employee, ['worked_on' => '2026-09-07', 'status' => 'present']);
        $this->attendance($employee, ['worked_on' => '2026-09-08', 'status' => 'absent']);
        $this->attendance($employee, ['worked_on' => '2026-09-09', 'status' => 'late']);

        $days = AttendanceRecord::forPeriod('2026-09-07', '2026-09-09')
            ->where('employee_id', $employee->id)
            ->get();

        $this->assertCount(3, $days);
        $this->assertSame(1, $days->where('status', 'absent')->count());
    }

    public function test_the_period_scope_excludes_days_outside_it(): void
    {
        $employee = $this->employee();

        $this->attendance($employee, ['worked_on' => '2026-09-01']);
        $this->attendance($employee, ['worked_on' => '2026-09-30']);

        $this->assertCount(1, AttendanceRecord::forPeriod('2026-09-01', '2026-09-15')->get());
    }

    /**
     * Attendance is deliberately not wired into pay.
     *
     * Payroll reads the employment contract in force on the payslip's own
     * date, so that a June payslip still reproduces June forever. If an
     * attendance row edited today could reach a payroll calculation, a
     * correction to an old timesheet would silently rewrite a payslip that has
     * already been paid and declared. Any future deduction must be an explicit
     * salary component on the run, never an implicit read of this table.
     */
    public function test_attendance_does_not_reach_payroll(): void
    {
        $payroll = file_get_contents(app_path('Services/Payroll/PayrollRunner.php'));

        $this->assertStringNotContainsString('AttendanceRecord', $payroll);
        $this->assertStringNotContainsString('attendance', $payroll);
    }

    public function test_the_recorder_is_remembered(): void
    {
        $record = $this->attendance($this->employee(), ['recorded_by' => $this->owner->id]);

        $this->assertTrue($record->recorder->is($this->owner));
    }

    /** Losing a person's timesheet with their staff file is intended: it is about them and nobody else. */
    public function test_deleting_an_employee_outright_takes_their_attendance(): void
    {
        $employee = $this->employee();
        $this->attendance($employee);

        $employee->forceDelete();

        $this->assertCount(0, AttendanceRecord::all());
    }
}
