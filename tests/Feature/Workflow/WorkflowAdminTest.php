<?php

namespace Tests\Feature\Workflow;

use App\Livewire\Workflow\Edit;
use App\Livewire\Workflow\Index;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use App\Support\WorkflowSubjects;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/**
 * The screens on which an approval path is written.
 *
 * The routes are registered here rather than assumed: navigation and routing
 * for these components are wired separately, and a test that depended on that
 * having happened would fail for a reason that has nothing to do with the
 * screens themselves.
 */
class WorkflowAdminTest extends WorkflowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function () {
            Route::get('/workflows', Index::class)->name('workflows');
            Route::get('/workflows/{workflow}', Edit::class)->name('workflows.edit');
        });
    }

    /**
     * The whole point of the screens: something defined here has to actually
     * run. A form that writes rows the engine cannot use is worse than none.
     */
    public function test_a_path_defined_on_the_screen_really_runs_an_approval(): void
    {
        $this->actingAs($this->owner);

        $manager = $this->memberAt(Role::MANAGER);

        Livewire::test(Index::class)
            ->call('startCreating')
            ->set('name', 'Expense approval')
            ->set('subjectType', Expense::class)
            ->call('create');

        $workflow = Workflow::query()->where('name', 'Expense approval')->firstOrFail();

        Livewire::test(Edit::class, ['workflow' => $workflow])
            ->call('addStep')
            ->set('stepName', 'Manager approves')
            ->set('approverMode', 'role')
            ->set('approverRole', Role::MANAGER)
            ->set('quorumMode', 'any')
            ->call('saveStep')
            ->assertHasNoErrors()
            ->call('makeDefault');

        $workflow->refresh();

        $this->assertTrue($workflow->is_active);
        $this->assertTrue($workflow->is_default);
        $this->assertSame(1, $workflow->steps->first()->position);

        $expense = $this->expense();
        $instance = app(WorkflowEngine::class)->start($expense, Workflow::defaultFor(Expense::class), $this->owner);

        $this->assertSame('running', $instance->status);
        $this->assertTrue($instance->assignments()->where('user_id', $manager->id)->exists());

        $instance = app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        $this->assertSame('approved', $instance->status);
    }

    /** A condition written on the screen has to be one the engine evaluates. */
    public function test_a_threshold_written_on_the_screen_skips_the_step_below_it(): void
    {
        $this->actingAs($this->owner);
        $this->memberAt(Role::MANAGER);

        $workflow = Workflow::create([
            'name' => 'Big spends',
            'subject_type' => Expense::class,
            'is_active' => true,
            'is_default' => true,
        ]);

        Livewire::test(Edit::class, ['workflow' => $workflow])
            ->call('addStep')
            ->set('stepName', 'Owner signs the big ones')
            ->set('approverMode', 'owner')
            ->call('addCondition')
            ->set('conditions.0.field', 'total')
            ->set('conditions.0.operator', '>=')
            ->set('conditions.0.value', '1000000')
            ->call('saveStep')
            ->assertHasNoErrors();

        // Stored as a number, not the string "1000000": string comparison
        // would sort a five-figure total above a seven-figure threshold.
        $this->assertSame(1000000, $workflow->steps()->first()->conditions[0]['value']);

        $small = app(WorkflowEngine::class)->start($this->expense(50_000), $workflow->fresh(), $this->owner);
        $this->assertSame('approved', $small->status, 'A small expense should skip the step, not stall on it.');

        $big = app(WorkflowEngine::class)->start($this->expense(5_000_000), $workflow->fresh(), $this->owner);
        $this->assertSame('running', $big->status);
    }

    /**
     * A step nobody can fill stalls in complete silence, so it is worth saying
     * at the moment the rule is written — but never worth refusing, because a
     * business may be writing the path for a job it is about to advertise.
     */
    public function test_a_step_naming_a_role_nobody_holds_warns_without_blocking_the_save(): void
    {
        $this->actingAs($this->owner);

        $workflow = Workflow::create([
            'name' => 'Cashier sign-off',
            'subject_type' => Expense::class,
            'is_active' => false,
            'is_default' => false,
        ]);

        Livewire::test(Edit::class, ['workflow' => $workflow])
            ->call('addStep')
            ->set('stepName', 'A cashier checks it')
            ->set('approverMode', 'role')
            ->set('approverRole', Role::CASHIER)
            ->call('saveStep')
            ->assertHasNoErrors()
            ->assertSee('stop and wait');

        $this->assertSame(1, $workflow->steps()->count(), 'The save must go through.');

        // And it keeps saying so, on the step, every time the screen is opened.
        Livewire::test(Edit::class, ['workflow' => $workflow->fresh()])
            ->assertSee('stop and wait');
    }

    /**
     * The most important correctness question on these screens.
     *
     * The engine walks a workflow's steps every time it advances, and an
     * assignment points at a step row. Editing in place would move an
     * approval already half decided onto different rules — or, deleting a
     * step, cascade its assignments away and leave the people who were asked
     * simply no longer asked. So the old rules are copied aside and the
     * running approvals moved onto the copy.
     */
    public function test_editing_a_path_leaves_an_approval_already_running_exactly_where_it_was(): void
    {
        $this->actingAs($this->owner);

        $manager = $this->memberAt(Role::MANAGER);
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $workflow = $this->workflow([
            ['name' => 'Manager approves', 'approver_mode' => 'role', 'approver_role' => Role::MANAGER],
            ['name' => 'Accountant approves', 'approver_mode' => 'role', 'approver_role' => Role::ACCOUNTANT],
        ], ['is_default' => true]);

        $instance = app(WorkflowEngine::class)->start($this->expense(), $workflow, $this->owner);

        $this->assertSame(1, $instance->position);
        $originalWorkflowId = $instance->workflow_id;

        // Rip the running step out from under it, and reorder what is left.
        Livewire::test(Edit::class, ['workflow' => $workflow->fresh()])
            ->call('removeStep', $workflow->steps->first()->id);

        $instance->refresh();

        // Moved onto the frozen copy, still at step 1, still with the manager.
        $this->assertNotSame($originalWorkflowId, $instance->workflow_id);
        $this->assertNotNull($instance->workflow->archived_from_id);
        $this->assertSame(1, $instance->position);
        $this->assertSame('running', $instance->status);
        $this->assertSame('Manager approves', $instance->currentStep()?->name);
        $this->assertTrue(
            $instance->assignments()->pending()->where('user_id', $manager->id)->exists(),
            'The person already asked must still be asked.',
        );

        // And it finishes under the rules it started with — both steps.
        $instance = app(WorkflowEngine::class)->act($instance, $manager, 'approved');
        $this->assertSame(2, $instance->position);

        $instance = app(WorkflowEngine::class)->act($instance, $accountant, 'approved');
        $this->assertSame('approved', $instance->status);

        // Meanwhile the live path is the edited one, and it is the only one
        // anybody can choose.
        $workflow->refresh();
        $this->assertSame(['Accountant approves'], $workflow->steps->pluck('name')->all());
        $this->assertTrue(Workflow::defaultFor(Expense::class)->is($workflow));
        $this->assertSame(1, Workflow::definitions()->count());
    }

    /** The frozen copy is a record, not a path anybody may edit or pick. */
    public function test_a_frozen_copy_is_not_offered_and_cannot_be_opened(): void
    {
        $this->actingAs($this->owner);
        $this->memberAt(Role::MANAGER);

        $workflow = $this->workflow([['name' => 'Manager approves']], ['is_default' => true]);
        app(WorkflowEngine::class)->start($this->expense(), $workflow, $this->owner);

        Livewire::test(Edit::class, ['workflow' => $workflow->fresh()])
            ->call('addStep')
            ->set('stepName', 'And the owner')
            ->set('approverMode', 'owner')
            ->call('saveStep');

        $archive = Workflow::query()->whereNotNull('archived_from_id')->firstOrFail();

        $this->assertFalse($archive->is_active);
        $this->assertFalse($archive->is_default);
        $this->assertSame(1, Workflow::definitions()->count());

        $this->get(route('workflows.edit', $archive))->assertNotFound();
    }

    public function test_only_one_path_per_subject_type_is_the_default(): void
    {
        $this->actingAs($this->owner);

        $first = $this->workflow([['name' => 'One']], ['name' => 'First', 'is_default' => true]);
        $second = $this->workflow([['name' => 'Two']], ['name' => 'Second']);

        Livewire::test(Index::class)->call('makeDefault', $second->id);

        $this->assertFalse($first->fresh()->is_default);
        $this->assertTrue($second->fresh()->is_default);
        $this->assertSame(1, Workflow::definitions()->forSubject(Expense::class)->where('is_default', true)->count());
    }

    /** A path with no steps approves everything the instant it starts. */
    public function test_a_path_with_no_steps_cannot_be_switched_on_or_made_the_default(): void
    {
        $this->actingAs($this->owner);

        $workflow = Workflow::create([
            'name' => 'Empty',
            'subject_type' => Expense::class,
            'is_active' => false,
            'is_default' => false,
        ]);

        Livewire::test(Index::class)
            ->call('toggleActive', $workflow->id)
            ->assertHasErrors('workflow')
            ->call('makeDefault', $workflow->id)
            ->assertHasErrors('workflow');

        $this->assertFalse($workflow->fresh()->is_active);
        $this->assertFalse($workflow->fresh()->is_default);
    }

    public function test_a_duplicate_copies_every_step_and_starts_switched_off(): void
    {
        $this->actingAs($this->owner);

        $workflow = $this->workflow([
            ['name' => 'One', 'quorum' => 'all', 'type' => 'review', 'due_days' => 3],
            ['name' => 'Two', 'approver_mode' => 'owner'],
        ], ['is_default' => true]);

        Livewire::test(Index::class)->call('duplicate', $workflow->id);

        $copy = Workflow::query()->where('name', $workflow->name.' (copy)')->firstOrFail();

        $this->assertFalse($copy->is_active);
        $this->assertFalse($copy->is_default);
        $this->assertSame(['One', 'Two'], $copy->steps->pluck('name')->all());
        // Model::create() does not backfill a column default, so a copy that
        // leaned on the schema would come back as approval/any/3 days lost.
        $this->assertSame('all', $copy->steps->first()->quorum);
        $this->assertSame('review', $copy->steps->first()->type);
        $this->assertSame(3, $copy->steps->first()->due_days);
        $this->assertSame([1, 2], $copy->steps->pluck('position')->all());
    }

    // ── Permissions ─────────────────────────────────────────────────────

    /** `workflows.view` shows the rules; `workflows.manage` rewrites them. */
    public function test_somebody_who_may_only_view_sees_the_rules_but_no_controls(): void
    {
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $this->assertTrue($accountant->can('workflows.view'));
        $this->assertFalse($accountant->can('workflows.manage'));

        $this->actingAs($accountant);

        $workflow = $this->workflow([['name' => 'Manager approves']], ['name' => 'Expense approval']);

        Livewire::test(Index::class)
            ->assertSee('Expense approval')
            ->assertDontSee('Duplicate')
            ->assertDontSee('New path');

        Livewire::test(Edit::class, ['workflow' => $workflow])
            ->assertSee('Manager approves')
            ->assertDontSee('Add a step');
    }

    public function test_the_actions_are_refused_to_somebody_who_may_only_view(): void
    {
        $this->actingAs($this->memberAt(Role::ACCOUNTANT));

        $workflow = $this->workflow([['name' => 'Manager approves']]);
        $stepId = $workflow->steps->first()->id;

        foreach ([
            ['startCreating', []],
            ['create', []],
            ['duplicate', [$workflow->id]],
            ['toggleActive', [$workflow->id]],
            ['makeDefault', [$workflow->id]],
            ['delete', [$workflow->id]],
        ] as [$method, $arguments]) {
            Livewire::test(Index::class)->call($method, ...$arguments)->assertForbidden();
        }

        foreach ([
            ['addStep', []],
            ['saveDetails', []],
            ['toggleActive', []],
            ['makeDefault', []],
            ['saveStep', []],
            ['editStep', [$stepId]],
            ['removeStep', [$stepId]],
            ['moveUp', [$stepId]],
        ] as [$method, $arguments]) {
            Livewire::test(Edit::class, ['workflow' => $workflow])
                ->call($method, ...$arguments)
                ->assertForbidden();
        }

        $this->assertSame(1, $workflow->fresh()->steps->count());
        $this->assertSame('Manager approves', $workflow->fresh()->steps->first()->name);
        $this->assertFalse($workflow->fresh()->is_default);
        $this->assertSame(1, Workflow::definitions()->count());
    }

    public function test_somebody_with_neither_ability_cannot_open_the_screen(): void
    {
        $this->actingAs($this->memberAt(Role::CASHIER));

        Livewire::test(Index::class)->assertForbidden();
    }

    // ── The catalogue ───────────────────────────────────────────────────

    public function test_the_condition_editor_offers_only_operators_the_engine_evaluates(): void
    {
        $this->actingAs($this->owner);

        $workflow = $this->workflow([['name' => 'One']]);

        Livewire::test(Edit::class, ['workflow' => $workflow])
            ->call('addStep')
            ->set('stepName', 'One')
            ->call('addCondition')
            ->set('conditions.0.field', 'total')
            ->set('conditions.0.operator', 'LIKE')
            ->set('conditions.0.value', '5')
            ->call('saveStep')
            ->assertHasErrors('conditions.0.operator');
    }

    public function test_the_fields_offered_are_the_ones_the_record_actually_has(): void
    {
        $fields = WorkflowSubjects::fields(Expense::class);

        $this->assertContains('total', $fields);
        $this->assertNotContains('company_id', $fields);
        $this->assertNotContains('id', $fields);
        $this->assertSame([], WorkflowSubjects::fields('App\\Models\\NotApprovable'));
    }

    public function test_what_a_used_path_approves_cannot_be_changed_underneath_it(): void
    {
        $this->actingAs($this->owner);
        $this->memberAt(Role::MANAGER);

        $workflow = $this->workflow([['name' => 'Manager approves']], ['is_default' => true]);
        app(WorkflowEngine::class)->start($this->expense(), $workflow, $this->owner);

        Livewire::test(Edit::class, ['workflow' => $workflow->fresh()])
            ->set('subjectType', Contract::class)
            ->call('saveDetails')
            ->assertHasErrors('subjectType');

        $this->assertSame(Expense::class, $workflow->fresh()->subject_type);
    }

    public function test_removing_a_path_keeps_what_is_still_running_on_it(): void
    {
        $this->actingAs($this->owner);
        $manager = $this->memberAt(Role::MANAGER);

        $workflow = $this->workflow([['name' => 'Manager approves']], ['is_default' => true]);
        $instance = app(WorkflowEngine::class)->start($this->expense(), $workflow, $this->owner);

        Livewire::test(Index::class)->call('delete', $workflow->id);

        $this->assertSoftDeleted('workflows', ['id' => $workflow->id]);

        $instance->refresh();

        $this->assertNotNull($instance->workflow, 'The running approval must still have rules to be judged by.');
        $this->assertSame('running', $instance->status);

        $instance = app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        $this->assertSame('approved', $instance->status);
        $this->assertSame(WorkflowInstance::FINISHED[0], $instance->status);
    }
}
