<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;

class ProjectPermissionTest extends ProjectTestCase
{
    public function test_the_catalogue_carries_the_project_abilities(): void
    {
        $this->assertSame(['view', 'manage', 'log-time'], Permissions::CATALOGUE['Projects']);
        $this->assertTrue(Gate::has('projects.view'));
        $this->assertTrue(Gate::has('projects.manage'));
        $this->assertTrue(Gate::has('projects.log-time'));
    }

    public function test_a_manager_may_create_and_manage_projects(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $this->assertTrue($manager->can('create', Project::class));
        $this->assertTrue($manager->can('update', $this->project()));
    }

    /** Doing the work and pricing it are different jobs. */
    public function test_a_sales_officer_may_log_time_but_not_manage(): void
    {
        $officer = $this->memberAt(Role::SALES_OFFICER);
        $project = $this->project();

        $this->assertTrue($officer->can('logTime', $project));
        $this->assertFalse($officer->can('update', $project));
        $this->assertFalse($officer->can('create', Project::class));
    }

    public function test_an_accountant_reads_but_neither_manages_nor_logs_time(): void
    {
        $accountant = $this->memberAt(Role::ACCOUNTANT);
        $project = $this->project();

        $this->assertTrue($accountant->can('view', $project));
        $this->assertFalse($accountant->can('update', $project));
        $this->assertFalse($accountant->can('logTime', $project));
    }

    public function test_a_cashier_has_no_access_at_all(): void
    {
        $cashier = $this->memberAt(Role::CASHIER);
        $project = $this->project();

        $this->assertFalse($cashier->can('view', $project));
        $this->assertFalse($cashier->can('logTime', $project));
    }

    public function test_manage_does_not_reach_another_company(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $elsewhere = $this->projectInAnotherCompany();

        $this->assertFalse($manager->can('view', $elsewhere));
        $this->assertFalse($manager->can('update', $elsewhere));
    }

    protected function projectInAnotherCompany(): Project
    {
        $stranger = \App\Models\User::factory()->create();
        $other = \App\Models\Company::create([
            'slug' => 'other-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)),
            'name' => 'Other Sarl', 'owner_id' => $stranger->id, 'currency' => 'XAF',
            'plan' => 'business', 'account_type' => 'active',
        ]);
        $this->joinCompany($other, $stranger, Role::OWNER);

        app(\App\Support\CurrentCompany::class)->set($other);
        $project = $this->project(['created_by' => $stranger->id]);
        app(\App\Support\CurrentCompany::class)->set($this->company);

        return $project;
    }
}
