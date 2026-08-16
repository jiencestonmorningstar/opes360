<?php

namespace Tests\Feature\Departments;

use App\Livewire\Business\Departments;
use App\Models\Department;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

class DepartmentScreenTest extends DepartmentTestCase
{
    public function test_the_catalogue_carries_the_department_abilities(): void
    {
        $this->assertSame(['view', 'manage'], Permissions::CATALOGUE['Departments']);
        $this->assertTrue(Gate::has('departments.view'));
        $this->assertTrue(Gate::has('departments.manage'));
    }

    public function test_a_manager_may_run_the_department_list(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $this->assertTrue($manager->can('viewAny', Department::class));
        $this->assertTrue($manager->can('create', Department::class));
    }

    /** Reading the chart and redrawing it are different jobs. */
    public function test_an_accountant_reads_the_list_but_cannot_redraw_it(): void
    {
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $this->assertTrue($accountant->can('viewAny', Department::class));
        $this->assertFalse($accountant->can('create', Department::class));
    }

    public function test_a_sales_officer_may_not(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);

        $this->assertFalse($clerk->can('create', Department::class));
    }

    public function test_the_screen_lists_the_active_departments(): void
    {
        $this->actingAs($this->owner);

        $this->department(['name' => 'Finance']);
        $this->department(['name' => 'Typing pool', 'is_active' => false]);

        Livewire::test(Departments::class)
            ->assertSee('Finance')
            ->assertDontSee('Typing pool');
    }

    public function test_the_screen_shows_archived_departments_on_request(): void
    {
        $this->actingAs($this->owner);

        $this->department(['name' => 'Typing pool', 'is_active' => false]);

        Livewire::test(Departments::class)
            ->set('showArchived', true)
            ->assertSee('Typing pool');
    }

    public function test_a_department_can_be_created_from_the_screen(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(Departments::class)
            ->set('name', 'Procurement')
            ->call('save');

        $this->assertDatabaseHas('departments', [
            'company_id' => $this->company->id,
            'name' => 'Procurement',
        ]);
    }

    public function test_a_duplicate_name_is_refused_with_a_usable_message(): void
    {
        $this->actingAs($this->owner);
        $this->department(['name' => 'Finance']);

        Livewire::test(Departments::class)
            ->set('name', 'Finance')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, Department::query()->count());
    }

    public function test_renaming_a_department_does_not_collide_with_itself(): void
    {
        $this->actingAs($this->owner);
        $department = $this->department(['name' => 'Finance']);

        Livewire::test(Departments::class)
            ->call('edit', $department->id)
            ->set('code', 'FIN')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('FIN', $department->fresh()->code);
    }

    /**
     * Its name is on payslips and documents that have already happened.
     * Deleting it would leave that history reading "—".
     */
    public function test_a_department_is_archived_rather_than_destroyed(): void
    {
        $this->actingAs($this->owner);
        $department = $this->department();

        Livewire::test(Departments::class)
            ->call('archive', $department->id);

        $this->assertFalse($department->fresh()->is_active);
        $this->assertNotNull($department->fresh());
    }

    public function test_an_archived_department_can_be_restored(): void
    {
        $this->actingAs($this->owner);
        $department = $this->department(['is_active' => false]);

        Livewire::test(Departments::class)
            ->call('restore', $department->id);

        $this->assertTrue($department->fresh()->is_active);
    }

    public function test_the_route_is_closed_to_a_cashier(): void
    {
        $this->actingAs($this->memberAt(Role::CASHIER));

        $this->get(route('departments'))->assertForbidden();
    }

    public function test_the_route_opens_for_a_manager(): void
    {
        $this->actingAs($this->memberAt(Role::MANAGER));

        $this->get(route('departments'))->assertOk();
    }
}
