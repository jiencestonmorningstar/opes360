<?php

namespace Tests\Feature\Projects;

use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\ProjectTimeEntry;
use App\Models\Role;
use RuntimeException;

class ProjectTest extends ProjectTestCase
{
    public function test_a_project_belongs_to_its_company(): void
    {
        $this->assertSame($this->company->id, $this->project()->company_id);
    }

    public function test_a_project_can_have_a_client(): void
    {
        $contact = $this->contact();
        $project = $this->project(['contact_id' => $contact->id]);

        $this->assertTrue($project->contact->is($contact));
    }

    public function test_a_milestone_belongs_to_a_project(): void
    {
        $project = $this->project();
        $milestone = ProjectMilestone::create(['project_id' => $project->id, 'name' => 'Design signed off']);

        $this->assertTrue($milestone->project->is($project));
        $this->assertTrue($project->milestones->first()->is($milestone));
    }

    /** Grouping is an arrangement, not a container — the same rule a Documents folder follows. */
    public function test_a_task_survives_its_milestone_being_deleted(): void
    {
        $project = $this->project();
        $milestone = ProjectMilestone::create(['project_id' => $project->id, 'name' => 'Design']);
        $task = ProjectTask::create([
            'project_id' => $project->id,
            'milestone_id' => $milestone->id,
            'title' => 'Draft the homepage',
        ]);

        $milestone->delete();

        $this->assertNotNull($task->fresh());
        $this->assertNull($task->fresh()->milestone_id);
    }

    public function test_a_task_can_be_assigned(): void
    {
        $developer = $this->memberAt(Role::SALES_OFFICER);
        $project = $this->project();

        $task = ProjectTask::create([
            'project_id' => $project->id,
            'title' => 'Draft the homepage',
            'assignee_id' => $developer->id,
        ]);

        $this->assertTrue($task->assignee->is($developer));
    }

    public function test_deleting_a_project_takes_its_tasks_and_milestones(): void
    {
        $project = $this->project();
        ProjectMilestone::create(['project_id' => $project->id, 'name' => 'Design']);
        ProjectTask::create(['project_id' => $project->id, 'title' => 'Draft the homepage']);

        $project->forceDelete();

        $this->assertDatabaseCount('project_milestones', 0);
        $this->assertDatabaseCount('project_tasks', 0);
    }

    // ── Time ──────────────────────────────────────────────────────────────

    public function test_logging_time_records_the_rate_at_the_time_it_was_logged(): void
    {
        $developer = $this->memberAt(Role::SALES_OFFICER);
        $project = $this->project(['default_hourly_rate' => 15_000]);

        $entry = ProjectTimeEntry::create([
            'project_id' => $project->id,
            'user_id' => $developer->id,
            'worked_on' => now()->toDateString(),
            'hours' => 3,
            'hourly_rate' => $project->default_hourly_rate,
            'is_billable' => true,
        ]);

        $project->update(['default_hourly_rate' => 20_000]);

        $this->assertSame('15000.00', $entry->fresh()->hourly_rate);
    }

    public function test_unbilled_hours_excludes_locked_entries(): void
    {
        $developer = $this->memberAt(Role::SALES_OFFICER);
        $project = $this->project();

        ProjectTimeEntry::create([
            'project_id' => $project->id, 'user_id' => $developer->id,
            'worked_on' => now()->toDateString(), 'hours' => 4, 'is_billable' => true,
        ]);
        ProjectTimeEntry::create([
            'project_id' => $project->id, 'user_id' => $developer->id,
            'worked_on' => now()->toDateString(), 'hours' => 2, 'is_billable' => true,
            'locked_at' => now(),
        ]);

        $this->assertSame(4.0, $project->unbilledHours());
    }

    public function test_a_locked_time_entry_cannot_be_edited(): void
    {
        $developer = $this->memberAt(Role::SALES_OFFICER);
        $entry = ProjectTimeEntry::create([
            'project_id' => $this->project()->id, 'user_id' => $developer->id,
            'worked_on' => now()->toDateString(), 'hours' => 4, 'is_billable' => true,
            'locked_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        $entry->update(['hours' => 8]);
    }

    public function test_a_locked_time_entry_cannot_be_deleted(): void
    {
        $developer = $this->memberAt(Role::SALES_OFFICER);
        $entry = ProjectTimeEntry::create([
            'project_id' => $this->project()->id, 'user_id' => $developer->id,
            'worked_on' => now()->toDateString(), 'hours' => 4, 'is_billable' => true,
            'locked_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);

        $entry->delete();
    }

    public function test_an_unlocked_entry_can_still_be_corrected(): void
    {
        $developer = $this->memberAt(Role::SALES_OFFICER);
        $entry = ProjectTimeEntry::create([
            'project_id' => $this->project()->id, 'user_id' => $developer->id,
            'worked_on' => now()->toDateString(), 'hours' => 4, 'is_billable' => true,
        ]);

        $entry->update(['hours' => 3.5]);

        $this->assertSame('3.50', $entry->fresh()->hours);
    }

    // ── Cost ──────────────────────────────────────────────────────────────

    public function test_cost_to_date_combines_labour_and_expenses(): void
    {
        $developer = $this->memberAt(Role::SALES_OFFICER);
        $project = $this->project();

        ProjectTimeEntry::create([
            'project_id' => $project->id, 'user_id' => $developer->id,
            'worked_on' => now()->toDateString(), 'hours' => 4, 'hourly_rate' => 10_000,
            'is_billable' => true,
        ]);

        Expense::create([
            'description' => 'Stock photography', 'category' => 'other',
            'issue_date' => now()->toDateString(), 'amount' => 25_000, 'total' => 25_000,
            'status' => 'recorded', 'recorded_by' => $this->owner->id, 'project_id' => $project->id,
        ]);

        $this->assertSame(65_000.0, $project->costToDate());
    }

    /** An internal project costs money and earns none; treating it as a loss makes the report useless. */
    public function test_an_internal_project_is_not_billable(): void
    {
        $project = $this->project(['is_billable' => false]);

        $this->assertSame(0, Project::billable()->count());
        $this->assertSame(1, Project::query()->count());
    }

    public function test_projects_are_not_a_switchable_module_off_by_default(): void
    {
        // On by default, like everything else — a business that does project
        // work should not have to know to look for the switch.
        $this->assertTrue(config('modules.projects.default'));
    }
}
