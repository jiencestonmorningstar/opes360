<?php

namespace Tests\Feature\Projects;

use App\Livewire\Projects\Index;
use App\Models\Project;
use App\Models\Role;
use Livewire\Livewire;

class ProjectScreenTest extends ProjectTestCase
{
    public function test_the_screen_lists_projects(): void
    {
        $this->actingAs($this->owner);
        $this->project(['name' => 'Website rebuild']);

        Livewire::test(Index::class)->assertSee('Website rebuild');
    }

    public function test_search_filters_by_name(): void
    {
        $this->actingAs($this->owner);
        $this->project(['name' => 'Website rebuild']);
        $this->project(['name' => 'Warehouse fit-out']);

        Livewire::test(Index::class)
            ->set('search', 'Website')
            ->assertSee('Website rebuild')
            ->assertDontSee('Warehouse fit-out');
    }

    public function test_the_status_filter_narrows_the_list(): void
    {
        $this->actingAs($this->owner);
        $this->project(['name' => 'Active one', 'status' => 'active']);
        $this->project(['name' => 'Done one', 'status' => 'completed']);

        Livewire::test(Index::class)
            ->set('status', 'completed')
            ->assertSee('Done one')
            ->assertDontSee('Active one');
    }

    public function test_a_manager_can_create_a_project(): void
    {
        $this->actingAs($this->memberAt(Role::MANAGER));

        Livewire::test(Index::class)
            ->set('name', 'New site launch')
            ->call('save');

        $this->assertDatabaseHas('projects', [
            'company_id' => $this->company->id,
            'name' => 'New site launch',
            'status' => 'planning',
        ]);
    }

    public function test_a_sales_officer_cannot_create_a_project(): void
    {
        $this->actingAs($this->memberAt(Role::SALES_OFFICER));

        Livewire::test(Index::class)
            ->set('name', 'Should not work')
            ->call('save');

        $this->assertDatabaseMissing('projects', ['name' => 'Should not work']);
    }

    public function test_cancelling_a_project_does_not_delete_it(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project();

        Livewire::test(Index::class)->call('archive', $project->id);

        $this->assertSame('cancelled', $project->fresh()->status);
        $this->assertNotNull($project->fresh());
    }

    public function test_the_route_is_closed_to_a_cashier(): void
    {
        $this->actingAs($this->memberAt(Role::CASHIER));

        $this->get(route('projects'))->assertForbidden();
    }

    public function test_the_route_opens_for_a_sales_officer(): void
    {
        $this->actingAs($this->memberAt(Role::SALES_OFFICER));

        $this->get(route('projects'))->assertOk();
    }
}
