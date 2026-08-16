<?php

namespace Tests\Feature\Hr;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Position;
use App\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PositionTest extends HrTestCase
{
    public function test_a_position_belongs_to_the_current_company(): void
    {
        $this->assertSame($this->company->id, $this->position()->company_id);
    }

    public function test_a_position_can_sit_inside_a_department(): void
    {
        $department = \App\Models\Department::create(['name' => 'Finance']);
        $position = $this->position(['department_id' => $department->id]);

        $this->assertTrue($position->department->is($department));
    }

    /**
     * A department is reorganised far more often than the jobs inside it are
     * abolished. Losing "Accountant" because Finance was merged into
     * Operations would take every employee's job title with it.
     */
    public function test_deleting_a_department_leaves_the_position_standing(): void
    {
        $department = \App\Models\Department::create(['name' => 'Finance']);
        $position = $this->position(['department_id' => $department->id]);

        $department->forceDelete();

        $this->assertNotNull($position->fresh());
        $this->assertNull($position->fresh()->department_id);
    }

    public function test_two_positions_in_one_company_cannot_share_a_title(): void
    {
        $this->position(['title' => 'Driver']);

        $this->expectException(QueryException::class);

        $this->position(['title' => 'Driver']);
    }

    public function test_another_company_may_use_the_same_title(): void
    {
        $this->position(['title' => 'Driver']);

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertSame($other->id, $this->position(['title' => 'Driver'])->company_id);
    }

    public function test_retired_positions_are_hidden_from_the_active_list(): void
    {
        $this->position(['title' => 'Driver']);
        $this->position(['title' => 'Telex Operator', 'is_active' => false]);

        $this->assertSame(['Driver'], Position::active()->pluck('title')->all());
    }

    public function test_an_employee_holds_a_position(): void
    {
        $position = $this->position();
        $employee = $this->employee(['position_id' => $position->id]);

        $this->assertTrue($employee->positionRecord->is($position));
        $this->assertTrue($position->employees->first()->is($employee));
    }

    /**
     * Why the relation is not called `position`.
     *
     * The same trap that produced `Employee::departmentRecord()`. Employees
     * still carry a free-text `job_title` column — the API resource exposes
     * it, payroll snapshots it onto payslips and the team screens edit it —
     * and Eloquent resolves an attribute before it looks for a relation. A
     * relation named after an existing column returns the typed string
     * forever and nothing errors. This test exists so nobody "tidies" the
     * name back.
     */
    public function test_the_free_text_job_title_and_the_relation_are_different_things(): void
    {
        $position = $this->position(['title' => 'Accountant']);
        $employee = $this->employee([
            'job_title' => 'whatever somebody typed',
            'position_id' => $position->id,
        ]);

        $this->assertSame('whatever somebody typed', $employee->job_title);
        $this->assertSame('Accountant', $employee->positionRecord->title);
    }

    /** The typed-in column stays. Deleting a business's own data to tidy a schema is not a migration. */
    public function test_the_original_free_text_column_survives(): void
    {
        $this->assertTrue(Schema::hasColumn('employees', 'job_title'));
        $this->assertTrue(Schema::hasColumn('employees', 'position_id'));
    }

    /**
     * A position is a label on a person, not a container holding them.
     * Abolishing a role must never delete the staff file.
     */
    public function test_deleting_a_position_does_not_delete_its_employees(): void
    {
        $position = $this->position();
        $employee = $this->employee(['job_title' => 'Accountant', 'position_id' => $position->id]);

        $position->forceDelete();

        $this->assertNotNull($employee->fresh());
        $this->assertNull($employee->fresh()->position_id);
        $this->assertSame('Accountant', $employee->fresh()->job_title);
    }

    /**
     * The backfill the migration performs, re-run against rows created after
     * it. Every distinct typed job title should be able to become a real
     * position without the string being touched.
     */
    public function test_backfilling_matches_a_typed_title_to_a_position(): void
    {
        $employee = $this->employee(['job_title' => 'Night Watchman']);

        $position = $this->position(['title' => 'Night Watchman']);
        $employee->update(['position_id' => $position->id]);

        $this->assertSame('Night Watchman', $employee->fresh()->job_title);
        $this->assertTrue($employee->fresh()->positionRecord->is($position));
    }

    public function test_a_position_is_scoped_to_its_company(): void
    {
        $this->position(['title' => 'Driver']);

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $this->owner->id,
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertCount(0, Position::all());
    }

    public function test_an_employee_without_a_position_is_still_valid(): void
    {
        $this->assertNull($this->employee()->position_id);
    }

    public function test_headcount_counts_only_active_employees(): void
    {
        $position = $this->position();

        $this->employee(['position_id' => $position->id]);
        $this->employee(['position_id' => $position->id, 'status' => 'ended']);

        $this->assertSame(1, $position->fresh()->activeHeadcount());
    }

    public function test_employees_can_be_listed_by_position(): void
    {
        $position = $this->position();
        $this->employee(['position_id' => $position->id]);

        $this->assertCount(1, Employee::where('position_id', $position->id)->get());
    }
}
