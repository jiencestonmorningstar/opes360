<?php

namespace Tests\Feature\Audit;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\CurrentCompany;

/**
 * What the trail records, and what it deliberately does not.
 */
class AuditCaptureTest extends AuditTestCase
{
    public function test_a_deleted_record_is_still_identifiable_in_the_log(): void
    {
        /*
         * The whole point of the trail is the dispute that arrives after the
         * evidence is gone. Before subject_label the screen could only show a
         * ULID for a deleted row, which tells a lawyer nothing.
         */
        $contact = Contact::create(['name' => 'Vanishing Customer']);
        $id = $contact->id;
        $contact->delete();

        // 'trashed' rather than 'deleted' — Contact soft-deletes, and the
        // observer distinguishes the two because they are different acts.
        $entry = ActivityLog::query()
            ->where('subject_id', $id)
            ->where('event', 'trashed')
            ->firstOrFail();

        $this->assertSame('Vanishing Customer', $entry->subject_label);
    }

    public function test_a_sensitive_read_is_recorded_once_per_window(): void
    {
        $payslip = $this->makePayslip();

        Audit::accessed($payslip, 'Opened a payslip');
        Audit::accessed($payslip, 'Opened a payslip');
        Audit::accessed($payslip, 'Opened a payslip');

        // Three opens, one row: the deterrent value is in knowing the read
        // happened, not in counting page refreshes. Without the window a
        // payroll clerk's afternoon would out-number every write in the table.
        $this->assertSame(1, ActivityLog::where('event', 'accessed')->count());

        $entry = ActivityLog::where('event', 'accessed')->firstOrFail();
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame('Opened a payslip', data_get($entry->properties, 'reason'));
    }

    public function test_a_different_person_reading_the_same_record_is_its_own_row(): void
    {
        $payslip = $this->makePayslip();

        Audit::accessed($payslip, 'Opened a payslip');

        $other = User::factory()->create();
        $this->asRole($other, Role::MANAGER);
        $this->actingAs($other);

        Audit::accessed($payslip, 'Opened a payslip');

        $this->assertSame(2, ActivityLog::where('event', 'accessed')->count());
    }

    public function test_an_export_is_always_recorded_even_when_repeated(): void
    {
        // Exports are the one read that leaves the building, so they are never
        // collapsed into a window — two exports are two copies in the world.
        Audit::exported('payslips', 42, ['period' => '2026-08']);
        Audit::exported('payslips', 42, ['period' => '2026-08']);

        $this->assertSame(2, ActivityLog::where('event', 'exported')->count());
        $this->assertSame(42, data_get(ActivityLog::where('event', 'exported')->first()->properties, 'rows'));
    }

    public function test_read_logging_never_records_the_contents_of_what_was_read(): void
    {
        $payslip = $this->makePayslip();

        Audit::accessed($payslip, 'Opened a payslip');

        $entry = ActivityLog::where('event', 'accessed')->firstOrFail();

        // A log that copies the net pay into itself has doubled the number of
        // places the salary is readable, which is the opposite of the point.
        $this->assertStringNotContainsString('750000', json_encode($entry->properties));
    }

    protected function makePayslip(): Payslip
    {
        app(CurrentCompany::class)->set($this->company);

        $employee = Employee::create([
            'company_id' => $this->company->id,
            'first_name' => 'Marie',
            'last_name' => 'Kouam',
            'hired_on' => now()->subYear(),
            'status' => 'active',
        ]);

        $run = PayrollRun::create([
            'company_id' => $this->company->id,
            'period' => now()->startOfMonth()->toDateString(),
            'status' => 'draft',
        ]);

        return Payslip::create([
            'company_id' => $this->company->id,
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'base_salary' => 750000,
            'gross' => 750000,
            'net_pay' => 750000,
        ]);
    }
}
