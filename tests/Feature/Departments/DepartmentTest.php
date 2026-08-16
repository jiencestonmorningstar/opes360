<?php

namespace Tests\Feature\Departments;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use RuntimeException;

class DepartmentTest extends DepartmentTestCase
{
    public function test_a_department_belongs_to_the_current_company(): void
    {
        $this->assertSame($this->company->id, $this->department()->company_id);
    }

    public function test_two_departments_in_one_company_cannot_share_a_name(): void
    {
        $this->department(['name' => 'Finance']);

        $this->expectException(QueryException::class);

        $this->department(['name' => 'Finance']);
    }

    /** Two businesses both naming a department Finance is not a collision. */
    public function test_another_company_may_use_the_same_name(): void
    {
        $this->department(['name' => 'Finance']);

        $stranger = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $stranger->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($other, $stranger, Role::OWNER);
        app(CurrentCompany::class)->set($other);

        $elsewhere = Department::create(['name' => 'Finance']);

        $this->assertSame($other->id, $elsewhere->company_id);
        $this->assertSame(1, Department::query()->count());
    }

    public function test_a_department_can_sit_under_another(): void
    {
        $parent = $this->department(['name' => 'Operations']);
        $child = $this->department(['name' => 'Logistics', 'parent_id' => $parent->id]);

        $this->assertTrue($child->parent->is($parent));
        $this->assertTrue($parent->children->first()->is($child));
    }

    public function test_the_path_reads_from_the_root_down(): void
    {
        $parent = $this->department(['name' => 'Operations']);
        $child = $this->department(['name' => 'Logistics', 'parent_id' => $parent->id]);

        $this->assertSame('Operations / Logistics', $child->path());
    }

    /** A cycle makes path() recurse until the process dies. */
    public function test_a_department_cannot_be_moved_inside_its_own_descendant(): void
    {
        $parent = $this->department(['name' => 'Operations']);
        $child = $this->department(['name' => 'Logistics', 'parent_id' => $parent->id]);

        $this->expectException(RuntimeException::class);

        $parent->update(['parent_id' => $child->id]);
    }

    public function test_a_department_cannot_be_its_own_parent(): void
    {
        $department = $this->department();

        $this->expectException(RuntimeException::class);

        $department->update(['parent_id' => $department->id]);
    }

    public function test_the_tree_is_capped_at_four_levels(): void
    {
        $a = $this->department(['name' => 'A']);
        $b = $this->department(['name' => 'B', 'parent_id' => $a->id]);
        $c = $this->department(['name' => 'C', 'parent_id' => $b->id]);
        $d = $this->department(['name' => 'D', 'parent_id' => $c->id]);

        $this->expectException(RuntimeException::class);

        $this->department(['name' => 'E', 'parent_id' => $d->id]);
    }

    public function test_a_department_can_have_a_manager(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $department = $this->department(['manager_id' => $manager->id]);

        $this->assertTrue($department->manager->is($manager));
    }

    public function test_an_employee_belongs_to_a_department(): void
    {
        $department = $this->department();
        $employee = $this->employee(['department_id' => $department->id]);

        $this->assertTrue($employee->department->is($department));
        $this->assertTrue($department->employees->first()->is($employee));
    }

    /**
     * Losing the staff file to a mis-clicked department would be
     * unrecoverable. A department is a label on a person, not a container
     * holding them.
     */
    public function test_deleting_a_department_does_not_delete_its_employees(): void
    {
        $department = $this->department();
        $employee = $this->employee(['department_id' => $department->id]);

        $department->forceDelete();

        $this->assertNotNull($employee->fresh());
        $this->assertNull($employee->fresh()->department_id);
    }

    public function test_a_child_survives_its_parent_being_deleted(): void
    {
        $parent = $this->department(['name' => 'Operations']);
        $child = $this->department(['name' => 'Logistics', 'parent_id' => $parent->id]);

        $parent->forceDelete();

        $this->assertNotNull($child->fresh());
        $this->assertNull($child->fresh()->parent_id);
    }

    public function test_an_archived_department_is_out_of_the_active_list(): void
    {
        $this->department(['name' => 'Finance']);
        $this->department(['name' => 'Typing pool', 'is_active' => false]);

        $this->assertSame(2, Department::query()->count());
        $this->assertSame(1, Department::active()->count());
    }

    protected function employee(array $attributes = []): Employee
    {
        return Employee::create(array_merge([
            'first_name' => 'Aïcha',
            'last_name' => 'Njoya',
            'status' => 'active',
        ], $attributes));
    }
}
