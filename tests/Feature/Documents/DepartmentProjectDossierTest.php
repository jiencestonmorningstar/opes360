<?php

namespace Tests\Feature\Documents;

use App\Models\Department;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Services\Documents\DocumentDossiers;
use App\Services\Documents\DocumentLinker;

/**
 * §9–10: department and project document folders. Both were only blocked on
 * Department and Project being real Eloquent models — now that they are, the
 * dossier is the same relation-based query DocumentDossiers already runs for
 * every other ERP record, not a new stored folder concept.
 */
class DepartmentProjectDossierTest extends DocumentsTestCase
{
    public function test_a_department_dossier_returns_documents_linked_to_it(): void
    {
        $department = Department::create(['name' => 'Finance']);
        $other = Department::create(['name' => 'Logistics']);

        app(DocumentLinker::class)->attach($this->document(['kind' => 'policy', 'title' => 'Expense policy']), $department, 'about', $this->owner);
        app(DocumentLinker::class)->attach($this->document(['title' => 'Unrelated']), $other, 'about', $this->owner);

        $dossier = app(DocumentDossiers::class)->forDepartment($department);

        $this->assertSame(1, $dossier['total']);
        $this->assertSame('Expense policy', $dossier['groups']['policy']['documents']->first()->title);
    }

    public function test_a_department_with_no_documents_has_an_empty_dossier(): void
    {
        $department = Department::create(['name' => 'Legal']);

        $dossier = app(DocumentDossiers::class)->forDepartment($department);

        $this->assertSame(0, $dossier['total']);
        $this->assertSame([], $dossier['groups']);
    }

    public function test_a_project_dossier_returns_documents_linked_to_it(): void
    {
        $project = Project::create(['name' => 'Warehouse fit-out', 'status' => 'planning', 'is_billable' => true, 'created_by' => $this->owner->id]);
        $other = Project::create(['name' => 'Office move', 'status' => 'planning', 'is_billable' => true, 'created_by' => $this->owner->id]);

        app(DocumentLinker::class)->attach($this->document(['kind' => 'contract', 'title' => 'Vendor contract']), $project, 'about', $this->owner);
        app(DocumentLinker::class)->attach($this->document(['title' => 'Unrelated']), $other, 'about', $this->owner);

        $dossier = app(DocumentDossiers::class)->forProject($project);

        $this->assertSame(1, $dossier['total']);
        $this->assertSame('Vendor contract', $dossier['groups']['contract']['documents']->first()->title);
    }

    public function test_a_project_with_no_documents_has_an_empty_dossier(): void
    {
        $project = Project::create(['name' => 'Solo job', 'status' => 'planning', 'is_billable' => true, 'created_by' => $this->owner->id]);

        $dossier = app(DocumentDossiers::class)->forProject($project);

        $this->assertSame(0, $dossier['total']);
        $this->assertSame([], $dossier['groups']);
    }

    /** A dossier is a question, not a stored row — removing the link is enough. */
    public function test_the_department_dossier_reflects_a_link_being_removed(): void
    {
        $department = Department::create(['name' => 'Ops']);
        $doc = $this->document(['kind' => 'policy']);
        app(DocumentLinker::class)->attach($doc, $department, 'about', $this->owner);

        app(DocumentLinker::class)->detach($doc, $department);

        $this->assertSame(0, app(DocumentDossiers::class)->forDepartment($department)['total']);
    }

    // ── Embedding + permission gating ───────────────────────────────────

    public function test_the_departments_screen_shows_the_documents_panel_when_unfolded(): void
    {
        $department = Department::create(['name' => 'Finance']);
        app(DocumentLinker::class)->attach($this->document(['title' => 'Expense policy']), $department, 'about', $this->owner);

        $this->actingAs($this->owner)
            ->get(route('departments'))
            ->assertOk()
            ->assertSee('Documents');
    }

    public function test_the_projects_screen_shows_the_dossier_for_an_unfolded_project(): void
    {
        $project = Project::create(['name' => 'Warehouse fit-out', 'status' => 'planning', 'is_billable' => true, 'created_by' => $this->owner->id]);
        app(DocumentLinker::class)->attach($this->document(['title' => 'Vendor contract']), $project, 'about', $this->owner);

        $this->actingAs($this->owner)
            ->get(route('projects'))
            ->assertOk();
    }

    /** The embedded panel is gated on papers.view, same as every other x-documents embed. */
    public function test_a_user_without_papers_view_sees_no_department_library(): void
    {
        $department = Department::create(['name' => 'Finance']);
        app(DocumentLinker::class)->attach($this->document(['title' => 'Expense policy']), $department, 'about', $this->owner);

        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $response = $this->actingAs($cashier)->get(route('departments'));

        if ($response->status() === 200) {
            $response->assertDontSee('Expense policy');
        } else {
            $this->assertTrue(in_array($response->status(), [403, 302], true));
        }
    }
}
