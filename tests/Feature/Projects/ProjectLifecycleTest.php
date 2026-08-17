<?php

namespace Tests\Feature\Projects;

use App\Livewire\Projects\Index;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\Role;
use Livewire\Livewire;

/**
 * The project lifecycle — until now a project could only ever be planning or
 * cancelled, and the task/milestone models were entirely unreachable.
 */
class ProjectLifecycleTest extends ProjectTestCase
{
    // ── Status transitions ───────────────────────────────────────────────

    public function test_a_planning_project_can_be_started(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project(['status' => 'planning']);

        Livewire::test(Index::class)->call('transition', $project->id, 'active');

        $this->assertSame('active', $project->fresh()->status);
    }

    public function test_an_active_project_can_be_put_on_hold_and_resumed(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project(['status' => 'active']);

        $component = Livewire::test(Index::class);

        $component->call('transition', $project->id, 'on_hold');
        $this->assertSame('on_hold', $project->fresh()->status);

        $component->call('transition', $project->id, 'active');
        $this->assertSame('active', $project->fresh()->status);
    }

    public function test_completing_records_the_close_date(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project(['status' => 'active']);

        Livewire::test(Index::class)->call('transition', $project->id, 'completed');

        $project = $project->fresh();
        $this->assertSame('completed', $project->status);
        $this->assertNotNull($project->closed_on);
    }

    public function test_completing_with_unfinished_tasks_is_refused(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project(['status' => 'active']);

        ProjectTask::create([
            'company_id' => $this->company->id,
            'project_id' => $project->id,
            'title' => 'Still open',
            'status' => 'in_progress',
        ]);

        Livewire::test(Index::class)
            ->call('transition', $project->id, 'completed')
            ->assertHasErrors('projects');

        $this->assertSame('active', $project->fresh()->status);
    }

    public function test_a_completed_project_cannot_be_restarted(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project(['status' => 'completed']);

        Livewire::test(Index::class)
            ->call('transition', $project->id, 'active')
            ->assertHasErrors('projects');

        $this->assertSame('completed', $project->fresh()->status);
    }

    public function test_transitions_are_refused_without_the_manage_permission(): void
    {
        $project = $this->project(['status' => 'planning']);

        $this->actingAs($this->memberAt(Role::SALES_OFFICER));

        Livewire::test(Index::class)
            ->call('transition', $project->id, 'active')
            ->assertForbidden();
    }

    // ── Tasks ────────────────────────────────────────────────────────────

    public function test_a_task_can_be_added_to_a_project(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project();

        Livewire::test(Index::class)
            ->call('toggleOpen', $project->id)
            ->set('taskTitle', 'Wire the panel')
            ->call('addTask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('project_tasks', [
            'project_id' => $project->id,
            'title' => 'Wire the panel',
            'status' => 'todo',
        ]);
    }

    public function test_a_task_moves_between_states(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project();

        $task = ProjectTask::create([
            'company_id' => $this->company->id,
            'project_id' => $project->id,
            'title' => 'Wire the panel',
            'status' => 'todo',
        ]);

        $component = Livewire::test(Index::class)->call('toggleOpen', $project->id);

        $component->call('moveTask', $task->id, 'in_progress');
        $this->assertSame('in_progress', $task->fresh()->status);

        $component->call('moveTask', $task->id, 'done');
        $task = $task->fresh();
        $this->assertSame('done', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_a_task_cannot_move_to_a_made_up_state(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project();

        $task = ProjectTask::create([
            'company_id' => $this->company->id,
            'project_id' => $project->id,
            'title' => 'Wire the panel',
            'status' => 'todo',
        ]);

        Livewire::test(Index::class)
            ->call('toggleOpen', $project->id)
            ->call('moveTask', $task->id, 'exploded');

        $this->assertSame('todo', $task->fresh()->status);
    }

    // ── Milestones ───────────────────────────────────────────────────────

    public function test_a_milestone_can_be_added_and_completed(): void
    {
        $this->actingAs($this->owner);
        $project = $this->project();

        $component = Livewire::test(Index::class)
            ->call('toggleOpen', $project->id)
            ->set('milestoneName', 'Phase one handover')
            ->call('addMilestone')
            ->assertHasNoErrors();

        $milestone = ProjectMilestone::query()->firstWhere('name', 'Phase one handover');
        $this->assertNotNull($milestone);
        $this->assertFalse($milestone->isComplete());

        $component->call('completeMilestone', $milestone->id);
        $this->assertTrue($milestone->fresh()->isComplete());
    }
}
