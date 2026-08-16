# Workflow & Approval Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** one approval engine for the whole product, so Documents, Procurement,
Expenses and HR ask for an approval rather than each growing their own.

**Architecture:** A workflow is a named sequence of steps attached to a model
class. Starting one creates an **instance** against a specific record and
**assignments** against the people who must act. Acting on an assignment
records a **decision** and asks the engine to advance. Everything is
polymorphic — the engine never imports a business model, and a business module
never imports the engine's internals.

The engine is a **platform service**: it lives outside `config/modules.php`,
like users and settings, because a business that switches Procurement off must
not lose the approval history of the purchase orders it already raised.

**What this does not do.** It does not touch `document_approvals`, the
sales-specific approval that already exists and works. Migrating that is a
later, separate decision; two mechanisms briefly coexisting is much cheaper
than a rewrite of a working sales flow inside a foundational change.

**Tech Stack:** Laravel 12, Livewire 3, Tailwind v4, Pest/PHPUnit. No new
Composer packages.

**Roadmap item:** 1.2 of `docs/superpowers/plans/2026-08-16-build-everything-roadmap.md`
**Spec:** §17–18 of the master spec; ERP checklist #20.

---

## The model, in one picture

```
Workflow            "Purchase order approval", applies to PurchaseOrder
  └── WorkflowStep  ordered; each has a type, an approver rule, a quorum,
                    and an optional condition
        │
        │  start(subject)
        ▼
WorkflowInstance    this PO, at step 2, running
  ├── WorkflowAssignment   one per person who must act on the current step
  └── WorkflowDecision     the permanent record of who did what, and when
```

**Assignments are current; decisions are forever.** An assignment is deleted
or closed as the instance advances. A decision is never modified and never
deleted — it is the audit trail the brief requires (§30), and the reason
approval history survives a workflow being redefined afterwards.

---

## Decisions taken

| # | Decision | Why |
|---|---|---|
| 1 | Steps are **rows**, not JSON | Assignments and decisions point at a step. A step buried in a JSON blob cannot be referenced, so approval history would break the first time a workflow was edited |
| 2 | A **snapshot of the step** is copied onto the decision | Editing a workflow must not rewrite what already happened. "Approved by the Finance Manager" has to keep saying that after the step is renamed |
| 3 | **Quorum on the step**, expressed as `all` / `any` / a number | Covers single, multiple, sequential and parallel approval with one field instead of four flags |
| 4 | Conditions evaluate against the **subject**, in a tiny expression list, not PHP | A condition typed by an administrator must never be executable code |
| 5 | Rejection **stops** the instance; changes-requested **returns it to the submitter** | They are different outcomes. Collapsing them loses the difference between "no" and "not yet" |
| 6 | Approver rules resolve **at assignment time**, not definition time | A workflow that named user #14 breaks the day they leave. It names a role or a department, and the engine asks who that is now |
| 7 | Not in `config/modules.php` | Switching Procurement off must not delete the approval history of the POs already raised |

---

## File structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_08_26_000001_create_workflow_tables.php` | All five tables |
| `app/Models/Workflow.php` | The definition |
| `app/Models/WorkflowStep.php` | One step and its approver rule |
| `app/Models/WorkflowInstance.php` | One run against one record |
| `app/Models/WorkflowAssignment.php` | One person's outstanding action |
| `app/Models/WorkflowDecision.php` | The permanent record of an action |
| `app/Support/WorkflowApprovers.php` | Turns an approver rule into a list of users |
| `app/Support/WorkflowConditions.php` | Evaluates a step's condition against a subject |
| `app/Services/Workflow/WorkflowEngine.php` | start, act, advance, cancel |
| `app/Models/Concerns/Approvable.php` | The trait a business model uses |
| `app/Policies/WorkflowPolicy.php` | `workflows.view` / `workflows.manage` |
| `tests/Feature/Workflow/WorkflowTestCase.php` | Shared setup |
| `tests/Feature/Workflow/WorkflowDefinitionTest.php` | Task 1 |
| `tests/Feature/Workflow/WorkflowApproverTest.php` | Task 2 |
| `tests/Feature/Workflow/WorkflowConditionTest.php` | Task 3 |
| `tests/Feature/Workflow/WorkflowEngineTest.php` | Tasks 4–6 |
| `tests/Feature/Workflow/WorkflowInboxTest.php` | Task 7 |

---

### Task 1: The schema and the definition

**Files:**
- Create: `database/migrations/2026_08_26_000001_create_workflow_tables.php`
- Create: `app/Models/Workflow.php`, `app/Models/WorkflowStep.php`
- Create: `tests/Feature/Workflow/WorkflowTestCase.php`
- Test: `tests/Feature/Workflow/WorkflowDefinitionTest.php`

- [ ] **Step 1: Write the shared test case**

```php
<?php

namespace Tests\Feature\Workflow;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class WorkflowTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function memberAt(string $role): User
    {
        $user = User::factory()->create();

        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        return $user;
    }

    /**
     * A workflow with the given steps, in order.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    protected function workflow(array $steps, array $attributes = []): Workflow
    {
        $workflow = Workflow::create(array_merge([
            'name' => 'Expense approval',
            'subject_type' => Expense::class,
            'is_active' => true,
        ], $attributes));

        foreach ($steps as $position => $step) {
            $workflow->steps()->create(array_merge([
                'position' => $position + 1,
                'name' => 'Step '.($position + 1),
                'type' => 'approval',
                'approver_mode' => 'role',
                'approver_role' => Role::MANAGER,
                'quorum' => 'any',
            ], $step));
        }

        return $workflow->fresh();
    }

    /** A subject to run a workflow against. Expenses are the simplest real one. */
    protected function expense(float $amount = 100_000): Expense
    {
        return Expense::create([
            'reference' => 'EXP-'.Str::upper(Str::random(5)),
            'description' => 'Generator fuel',
            'spent_on' => now()->toDateString(),
            'amount' => $amount,
            'total' => $amount,
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ]);
    }
}
```

**Note:** confirm the `expenses` column names against
`database/migrations/*_create_expenses_table.php` before running — if `total`
or `status` differ, fix the helper, not the test that uses it.

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature\Workflow;

use App\Models\Expense;
use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowStep;

class WorkflowDefinitionTest extends WorkflowTestCase
{
    public function test_a_workflow_belongs_to_a_company_and_a_subject_type(): void
    {
        $workflow = $this->workflow([[]]);

        $this->assertSame($this->company->id, $workflow->company_id);
        $this->assertSame(Expense::class, $workflow->subject_type);
    }

    public function test_steps_come_back_in_order(): void
    {
        $workflow = $this->workflow([
            ['name' => 'Manager review', 'position' => 1],
            ['name' => 'Finance approval', 'position' => 2],
            ['name' => 'Director sign-off', 'position' => 3],
        ]);

        $this->assertSame(
            ['Manager review', 'Finance approval', 'Director sign-off'],
            $workflow->steps->pluck('name')->all(),
        );
    }

    /** Rows, not JSON: a decision has to be able to point at the step it was made on. */
    public function test_a_step_is_a_row_of_its_own(): void
    {
        $workflow = $this->workflow([[]]);

        $this->assertInstanceOf(WorkflowStep::class, $workflow->steps->first());
        $this->assertDatabaseCount('workflow_steps', 1);
    }

    public function test_a_step_records_how_many_approvals_it_needs(): void
    {
        $workflow = $this->workflow([
            ['quorum' => 'all'],
            ['quorum' => 'any'],
            ['quorum' => '2'],
        ]);

        $this->assertSame(['all', 'any', '2'], $workflow->steps->pluck('quorum')->all());
    }

    public function test_only_one_workflow_per_subject_type_can_be_the_default(): void
    {
        $this->workflow([[]], ['name' => 'First', 'is_default' => true]);
        $this->workflow([[]], ['name' => 'Second', 'is_default' => true]);

        $this->assertSame(
            1,
            Workflow::query()->where('subject_type', Expense::class)->where('is_default', true)->count(),
        );
        $this->assertSame('Second', Workflow::query()->where('is_default', true)->value('name'));
    }

    public function test_an_inactive_workflow_is_not_offered(): void
    {
        $this->workflow([[]], ['name' => 'Retired', 'is_active' => false]);
        $this->workflow([[]], ['name' => 'Current']);

        $this->assertSame(2, Workflow::query()->count());
        $this->assertSame(1, Workflow::active()->count());
    }

    public function test_a_workflow_is_scoped_to_its_company(): void
    {
        $this->workflow([[]]);

        $this->assertSame(1, Workflow::query()->count());
        $this->assertNotNull(Workflow::first()->company_id);
    }

    public function test_deleting_a_workflow_takes_its_steps(): void
    {
        $workflow = $this->workflow([[], []]);

        $workflow->forceDelete();

        $this->assertDatabaseCount('workflow_steps', 0);
    }

    public function test_an_approver_rule_can_name_a_role_a_department_or_a_person(): void
    {
        $workflow = $this->workflow([
            ['approver_mode' => 'role', 'approver_role' => Role::ACCOUNTANT],
            ['approver_mode' => 'owner'],
            ['approver_mode' => 'user', 'approver_user_id' => $this->owner->id],
        ]);

        $this->assertSame(
            ['role', 'owner', 'user'],
            $workflow->steps->pluck('approver_mode')->all(),
        );
    }
}
```

- [ ] **Step 3: Run it — expect failure**

```bash
export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:$PATH"
php artisan test --filter=WorkflowDefinitionTest
```

Expected: FAIL, `Class "App\Models\Workflow" not found`

- [ ] **Step 4: The migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // The model class this workflow approves. Polymorphic on purpose:
            // the engine never imports a business model.
            $table->string('subject_type');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'subject_type', 'is_active']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('workflow_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('position');
            $table->string('name');
            $table->string('type')->default('approval');   // review|approval|signature|task

            /*
             * Who acts. Resolved when the step is reached, never when it is
             * defined — a workflow that named a person breaks the day they
             * leave, so it names a role or a department and the engine asks
             * who that is now.
             */
            $table->string('approver_mode')->default('role'); // role|department|user|owner|manager|creator
            $table->string('approver_role')->nullable();
            $table->foreignUlid('approver_department_id')->nullable()
                ->constrained('departments')->nullOnDelete();
            $table->foreignId('approver_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // 'all' | 'any' | a number. One field instead of four flags.
            $table->string('quorum')->default('any');

            // [{field, operator, value}] — evaluated against the subject.
            // Never executable code; see WorkflowConditions.
            $table->json('conditions')->nullable();

            $table->unsignedInteger('due_days')->nullable();

            $table->timestamps();

            $table->index(['workflow_id', 'position']);
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('workflow_id')->constrained()->cascadeOnDelete();

            $table->string('subject_type');
            $table->string('subject_id');

            // running|approved|rejected|changes_requested|cancelled
            $table->string('status')->default('running');
            $table->unsignedInteger('position')->default(1);

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('workflow_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('workflow_step_id')->constrained('workflow_steps')->cascadeOnDelete();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // pending|acted|delegated|superseded
            $table->string('status')->default('pending');
            $table->date('due_on')->nullable();

            // Set when this assignment exists because somebody handed it over.
            $table->foreignId('delegated_from')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['workflow_instance_id', 'workflow_step_id', 'user_id']);
            $table->index(['company_id', 'user_id', 'status']);
        });

        Schema::create('workflow_decisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('workflow_instance_id')->constrained()->cascadeOnDelete();

            /*
             * The step is nulled rather than cascaded, and its name is copied
             * here. Editing a workflow must not rewrite what already
             * happened: "approved by the Finance Manager" has to keep saying
             * that after somebody renames the step.
             */
            $table->foreignUlid('workflow_step_id')->nullable()
                ->constrained('workflow_steps')->nullOnDelete();
            $table->string('step_name');

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // submitted|approved|rejected|changes_requested|delegated|cancelled
            $table->string('action');
            $table->text('comment')->nullable();
            $table->foreignId('delegated_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('acted_at');

            $table->timestamps();

            $table->index(['company_id', 'workflow_instance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_decisions');
        Schema::dropIfExists('workflow_assignments');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflows');
    }
};
```

**Index-size check:** the widest new unique key is
`workflow_assignments (workflow_instance_id, workflow_step_id, user_id)` —
26 + 26 + 8 = 60 bytes. Nowhere near MySQL's 3072 limit. Confirm anyway with
`php artisan opes:export-schema`.

- [ ] **Step 5: `app/Models/Workflow.php`**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A named sequence of steps, attached to a model class.
 *
 * The engine that runs these belongs to the platform, not to any module: a
 * business that switches Procurement off must keep the approval history of
 * the purchase orders it already raised.
 */
class Workflow extends Model
{
    use BelongsToCompany;
    use HasUlids;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class)->orderBy('position');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFor(Builder $query, string $subjectType): Builder
    {
        return $query->where('subject_type', $subjectType);
    }

    /** The one a module gets when it asks for "the" workflow for a record. */
    public static function defaultFor(string $subjectType): ?self
    {
        return self::query()->active()->for($subjectType)->where('is_default', true)->first();
    }

    protected static function booted(): void
    {
        /*
         * One default per subject type. Enforced here rather than by a unique
         * index, because "at most one row where is_default is true" is not a
         * uniqueness constraint any of the databases involved can express
         * portably.
         */
        static::saved(function (self $workflow) {
            if (! $workflow->is_default) {
                return;
            }

            self::query()
                ->where('subject_type', $workflow->subject_type)
                ->whereKeyNot($workflow->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }
}
```

- [ ] **Step 6: `app/Models/WorkflowStep.php`**

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowStep extends Model
{
    use BelongsToCompany;
    use HasUlids;

    public const TYPES = [
        'review' => 'Review',
        'approval' => 'Approval',
        'signature' => 'Signature',
        'task' => 'Task',
    ];

    public const APPROVER_MODES = [
        'role' => 'Anyone with a role',
        'department' => 'A department’s manager',
        'user' => 'A named person',
        'owner' => 'The business owner',
        'manager' => 'The submitter’s department manager',
        'creator' => 'Whoever raised the record',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function approverDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'approver_department_id');
    }

    public function approverUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }

    /**
     * How many approvals close this step, given how many people were asked.
     *
     * 'any' is 1 rather than 0 — a step nobody has to approve is not a step.
     */
    public function requiredApprovals(int $assigned): int
    {
        return match (true) {
            $this->quorum === 'all' => max(1, $assigned),
            $this->quorum === 'any' => 1,
            default => max(1, min((int) $this->quorum, max(1, $assigned))),
        };
    }
}
```

- [ ] **Step 7: Run — expect pass**

```bash
php artisan test --filter=WorkflowDefinitionTest
```

Expected: PASS, 9 tests.

- [ ] **Step 8: Regenerate the schema and commit**

```bash
export PATH="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"
php artisan opes:export-schema
```

```bash
git add database app/Models tests/Feature/Workflow
git commit -m "Give the product one place to define an approval"
```

---

### Task 2: Resolving who actually approves

**Files:**
- Create: `app/Support/WorkflowApprovers.php`
- Test: `tests/Feature/Workflow/WorkflowApproverTest.php`

The single most important property: **an approver rule is resolved when the
step is reached, never when it is written.** A workflow that stored user #14
is wrong the day that person leaves, and nobody discovers it until an invoice
sits unapproved for a week.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Workflow;

use App\Models\Department;
use App\Models\Role;
use App\Models\WorkflowStep;
use App\Support\WorkflowApprovers;

class WorkflowApproverTest extends WorkflowTestCase
{
    public function test_a_role_step_resolves_to_everyone_holding_that_role(): void
    {
        $one = $this->memberAt(Role::MANAGER);
        $two = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::CASHIER);

        $step = $this->step(['approver_mode' => 'role', 'approver_role' => Role::MANAGER]);

        $this->assertEqualsCanonicalizing(
            [$one->id, $two->id],
            app(WorkflowApprovers::class)->resolve($step, $this->expense())->pluck('id')->all(),
        );
    }

    public function test_a_named_person_resolves_to_themselves(): void
    {
        $person = $this->memberAt(Role::ACCOUNTANT);

        $step = $this->step(['approver_mode' => 'user', 'approver_user_id' => $person->id]);

        $this->assertSame(
            [$person->id],
            app(WorkflowApprovers::class)->resolve($step, $this->expense())->pluck('id')->all(),
        );
    }

    public function test_an_owner_step_resolves_to_the_business_owner(): void
    {
        $step = $this->step(['approver_mode' => 'owner']);

        $this->assertSame(
            [$this->owner->id],
            app(WorkflowApprovers::class)->resolve($step, $this->expense())->pluck('id')->all(),
        );
    }

    public function test_a_department_step_resolves_to_that_departments_manager(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $department = Department::create(['name' => 'Finance', 'manager_id' => $manager->id]);

        $step = $this->step([
            'approver_mode' => 'department',
            'approver_department_id' => $department->id,
        ]);

        $this->assertSame(
            [$manager->id],
            app(WorkflowApprovers::class)->resolve($step, $this->expense())->pluck('id')->all(),
        );
    }

    /**
     * A step nobody can fill must not silently pass. An approval that
     * approves itself because the approver left is worse than a stuck one:
     * the stuck one gets noticed.
     */
    public function test_a_department_with_no_manager_resolves_to_nobody(): void
    {
        $department = Department::create(['name' => 'Finance']);

        $step = $this->step([
            'approver_mode' => 'department',
            'approver_department_id' => $department->id,
        ]);

        $this->assertTrue(
            app(WorkflowApprovers::class)->resolve($step, $this->expense())->isEmpty()
        );
    }

    public function test_a_person_who_has_left_the_company_is_not_an_approver(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->company->users()->updateExistingPivot($manager->id, ['status' => 'removed']);

        $step = $this->step(['approver_mode' => 'role', 'approver_role' => Role::MANAGER]);

        $this->assertTrue(
            app(WorkflowApprovers::class)->resolve($step, $this->expense())->isEmpty()
        );
    }

    public function test_a_creator_step_resolves_to_whoever_raised_the_record(): void
    {
        $step = $this->step(['approver_mode' => 'creator']);
        $expense = $this->expense();

        $this->assertSame(
            [$this->owner->id],
            app(WorkflowApprovers::class)->resolve($step, $expense)->pluck('id')->all(),
        );
    }

    protected function step(array $attributes = []): WorkflowStep
    {
        return $this->workflow([$attributes])->steps->first();
    }
}
```

- [ ] **Step 2: Run it — expect failure**

```bash
php artisan test --filter=WorkflowApproverTest
```

Expected: FAIL, `Class "App\Support\WorkflowApprovers" not found`

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns "the Finance manager" into a list of people, now.
 *
 * Resolution happens when a step is reached, never when it is written. A
 * workflow that stored a user id is wrong the day that person leaves, and
 * nobody finds out until an invoice has sat unapproved for a week.
 *
 * An unresolvable step returns nobody. It must never fall back to "anyone" or
 * to the owner: a step that approves itself because its approver left is
 * worse than a stuck one, because the stuck one gets noticed.
 */
class WorkflowApprovers
{
    /** @return Collection<int, User> */
    public function resolve(WorkflowStep $step, Model $subject): Collection
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return collect();
        }

        $candidates = match ($step->approver_mode) {
            'user' => $this->byId($step->approver_user_id),
            'owner' => $this->byId($company->owner_id),
            'creator' => $this->byId($subject->getAttribute('created_by')),
            'role' => $this->byRole($step->approver_role),
            'department' => $this->byId(
                Department::find($step->approver_department_id)?->manager_id
            ),
            'manager' => $this->submittersManager($subject),
            default => collect(),
        };

        // Whoever is left must still be an active member of this company.
        return $candidates->filter(fn (User $user) => $this->isActiveMember($user, $company->id))->values();
    }

    /** @return Collection<int, User> */
    protected function byId(mixed $id): Collection
    {
        if ($id === null) {
            return collect();
        }

        $user = User::find($id);

        return $user === null ? collect() : collect([$user]);
    }

    /** @return Collection<int, User> */
    protected function byRole(?string $roleSlug): Collection
    {
        if ($roleSlug === null) {
            return collect();
        }

        $company = app(CurrentCompany::class)->get();

        return User::query()
            ->whereHas('companies', fn ($query) => $query
                ->where('companies.id', $company->id)
                ->where('company_user.status', 'active')
                ->whereHas('roleFor', fn ($r) => $r->where('slug', $roleSlug)))
            ->get();
    }

    /** @return Collection<int, User> */
    protected function submittersManager(Model $subject): Collection
    {
        $creatorId = $subject->getAttribute('created_by');

        if ($creatorId === null) {
            return collect();
        }

        $employee = \App\Models\Employee::query()->where('user_id', $creatorId)->first();

        return $this->byId($employee?->department?->manager_id);
    }

    protected function isActiveMember(User $user, string $companyId): bool
    {
        return $user->companies()
            ->where('companies.id', $companyId)
            ->wherePivot('status', 'active')
            ->exists();
    }
}
```

**Before writing `byRole`, read `app/Models/User.php` and check how the
company pivot exposes the role.** The `whereHas('roleFor', …)` above is a
placeholder shape for whatever that relation is actually called; if the pivot
carries `role_id` directly, the correct query is a join on `company_user`
filtered by `role_id`, and it should be written that way instead. Do not invent
a relation that does not exist.

- [ ] **Step 4: Run — expect pass**

```bash
php artisan test --filter=WorkflowApproverTest
```

Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support tests/Feature/Workflow
git commit -m "Resolve an approver when the step is reached, not when it is written"
```

---

### Task 3: Conditions

**Files:**
- Create: `app/Support/WorkflowConditions.php`
- Test: `tests/Feature/Workflow/WorkflowConditionTest.php`

Amount-based approval — "under 10 million a manager signs, over it goes to the
director" — is the single most-requested rule in the brief (§18). It is
expressed as data and evaluated by a tiny matcher. **A condition typed by an
administrator is never executable code**, so there is no `eval`, no callable,
and no expression language.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Workflow;

use App\Support\WorkflowConditions;

class WorkflowConditionTest extends WorkflowTestCase
{
    public function test_a_step_with_no_conditions_always_applies(): void
    {
        $step = $this->workflow([['conditions' => null]])->steps->first();

        $this->assertTrue(app(WorkflowConditions::class)->passes($step, $this->expense(50)));
    }

    public function test_an_amount_threshold_decides_whether_a_step_applies(): void
    {
        $step = $this->workflow([[
            'conditions' => [['field' => 'total', 'operator' => '>=', 'value' => 10_000_000]],
        ]])->steps->first();

        $conditions = app(WorkflowConditions::class);

        $this->assertFalse($conditions->passes($step, $this->expense(9_999_999)));
        $this->assertTrue($conditions->passes($step, $this->expense(10_000_000)));
        $this->assertTrue($conditions->passes($step, $this->expense(50_000_000)));
    }

    public function test_every_condition_must_pass(): void
    {
        $step = $this->workflow([[
            'conditions' => [
                ['field' => 'total', 'operator' => '>=', 'value' => 1_000],
                ['field' => 'status', 'operator' => '=', 'value' => 'draft'],
            ],
        ]])->steps->first();

        $this->assertTrue(app(WorkflowConditions::class)->passes($step, $this->expense(5_000)));
    }

    public function test_a_condition_on_a_field_the_subject_does_not_have_fails_closed(): void
    {
        // Fails closed: the step is skipped rather than applied. A typo in an
        // admin screen must not silently insert an approval nobody expects,
        // and must not silently remove one either — skipping is the outcome
        // that shows up as "why did this not need approval", which gets fixed.
        $step = $this->workflow([[
            'conditions' => [['field' => 'not_a_column', 'operator' => '>', 'value' => 1]],
        ]])->steps->first();

        $this->assertFalse(app(WorkflowConditions::class)->passes($step, $this->expense()));
    }

    public function test_an_unknown_operator_fails_closed(): void
    {
        $step = $this->workflow([[
            'conditions' => [['field' => 'total', 'operator' => 'DROP TABLE', 'value' => 1]],
        ]])->steps->first();

        $this->assertFalse(app(WorkflowConditions::class)->passes($step, $this->expense()));
    }

    public function test_the_supported_operators_all_work(): void
    {
        $conditions = app(WorkflowConditions::class);
        $expense = $this->expense(100);

        foreach ([
            ['>', 99, true], ['>', 100, false],
            ['>=', 100, true], ['<', 101, true],
            ['<=', 100, true], ['=', 100, true],
            ['!=', 99, true], ['!=', 100, false],
        ] as [$operator, $value, $expected]) {
            $step = $this->workflow([[
                'conditions' => [['field' => 'total', 'operator' => $operator, 'value' => $value]],
            ]], ['name' => 'W'.$operator.$value])->steps->first();

            $this->assertSame($expected, $conditions->passes($step, $expense), $operator.' '.$value);
        }
    }
}
```

- [ ] **Step 2: Run it — expect failure**

```bash
php artisan test --filter=WorkflowConditionTest
```

Expected: FAIL, `Class "App\Support\WorkflowConditions" not found`

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support;

use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether a step applies to this particular record.
 *
 * "Under ten million a manager signs; over it, the director" is the most
 * asked-for rule in the brief, and it is expressed as data: a list of
 * {field, operator, value}, matched here.
 *
 * There is deliberately no expression language, no callable and no eval. A
 * condition is typed by an administrator into a form, and a condition typed
 * into a form must never be executable code.
 *
 * Everything unrecognised fails closed — an unknown operator, a missing
 * field, a malformed row. A typo must not silently insert an approval nobody
 * expected, and skipping is the failure that gets reported ("why did this not
 * need approval?") rather than the one that goes unnoticed.
 */
class WorkflowConditions
{
    protected const OPERATORS = ['>', '>=', '<', '<=', '=', '!='];

    public function passes(WorkflowStep $step, Model $subject): bool
    {
        $conditions = $step->conditions;

        if (empty($conditions)) {
            return true;
        }

        foreach ($conditions as $condition) {
            if (! $this->matches($condition, $subject)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $condition */
    protected function matches(array $condition, Model $subject): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? null;
        $expected = $condition['value'] ?? null;

        if ($field === null || ! in_array($operator, self::OPERATORS, true)) {
            return false;
        }

        // getAttribute() would happily return null for a column that does not
        // exist, which is indistinguishable from a column that is null.
        if (! array_key_exists($field, $subject->getAttributes())) {
            return false;
        }

        $actual = $subject->getAttribute($field);

        if (is_numeric($actual) && is_numeric($expected)) {
            $actual = (float) $actual;
            $expected = (float) $expected;
        }

        return match ($operator) {
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            '=' => $actual == $expected,
            '!=' => $actual != $expected,
        };
    }
}
```

- [ ] **Step 4: Run — expect pass, then commit**

```bash
php artisan test --filter=WorkflowConditionTest
```

```bash
git add app/Support tests/Feature/Workflow
git commit -m "Decide amount-based approval from data, never from code"
```

---

### Task 4: The engine — starting and approving

**Files:**
- Create: `app/Services/Workflow/WorkflowEngine.php`
- Create: `app/Models/WorkflowInstance.php`, `WorkflowAssignment.php`, `WorkflowDecision.php`
- Create: `app/Models/Concerns/Approvable.php`
- Test: `tests/Feature/Workflow/WorkflowEngineTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Workflow;

use App\Models\Role;
use App\Models\WorkflowDecision;
use App\Models\WorkflowInstance;
use App\Services\Workflow\WorkflowEngine;
use RuntimeException;

class WorkflowEngineTest extends WorkflowTestCase
{
    public function test_starting_a_workflow_assigns_the_first_step(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $workflow = $this->workflow([['approver_mode' => 'role', 'approver_role' => Role::MANAGER]]);
        $expense = $this->expense();

        $instance = $this->engine()->start($expense, $workflow, $this->owner);

        $this->assertSame('running', $instance->status);
        $this->assertSame(1, $instance->position);
        $this->assertSame([$manager->id], $instance->assignments->pluck('user_id')->all());
    }

    public function test_starting_records_the_submission(): void
    {
        $this->memberAt(Role::MANAGER);
        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->assertSame('submitted', $instance->decisions->first()->action);
        $this->assertSame($this->owner->id, $instance->decisions->first()->user_id);
    }

    public function test_an_any_step_closes_on_the_first_approval(): void
    {
        $one = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([['quorum' => 'any']]),
            $this->owner,
        );

        $this->engine()->act($instance, $one, 'approved');

        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_an_all_step_waits_for_everybody(): void
    {
        $one = $this->memberAt(Role::MANAGER);
        $two = $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([['quorum' => 'all']]),
            $this->owner,
        );

        $this->engine()->act($instance, $one, 'approved');
        $this->assertSame('running', $instance->fresh()->status);

        $this->engine()->act($instance, $two, 'approved');
        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_a_numeric_quorum_closes_when_it_is_met(): void
    {
        $one = $this->memberAt(Role::MANAGER);
        $two = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([['quorum' => '2']]),
            $this->owner,
        );

        $this->engine()->act($instance, $one, 'approved');
        $this->assertSame('running', $instance->fresh()->status);

        $this->engine()->act($instance, $two, 'approved');
        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_approving_one_step_assigns_the_next(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([
                ['approver_mode' => 'role', 'approver_role' => Role::MANAGER],
                ['approver_mode' => 'role', 'approver_role' => Role::ACCOUNTANT],
            ]),
            $this->owner,
        );

        $this->engine()->act($instance, $manager, 'approved');

        $instance = $instance->fresh();

        $this->assertSame('running', $instance->status);
        $this->assertSame(2, $instance->position);
        $this->assertSame(
            [$accountant->id],
            $instance->assignments()->where('status', 'pending')->pluck('user_id')->all(),
        );
    }

    /** A step whose condition does not apply is stepped over, not stalled on. */
    public function test_a_step_whose_condition_fails_is_skipped(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start(
            $this->expense(5_000),
            $this->workflow([
                ['approver_mode' => 'role', 'approver_role' => Role::MANAGER],
                [
                    'approver_mode' => 'role',
                    'approver_role' => Role::ACCOUNTANT,
                    'conditions' => [['field' => 'total', 'operator' => '>=', 'value' => 10_000_000]],
                ],
            ]),
            $this->owner,
        );

        $this->engine()->act($instance, $manager, 'approved');

        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_rejection_stops_the_instance_immediately(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([
                ['approver_mode' => 'role', 'approver_role' => Role::MANAGER],
                ['approver_mode' => 'role', 'approver_role' => Role::ACCOUNTANT],
            ]),
            $this->owner,
        );

        $this->engine()->act($instance, $manager, 'rejected', 'Not budgeted.');

        $instance = $instance->fresh();

        $this->assertSame('rejected', $instance->status);
        $this->assertSame(0, $instance->assignments()->where('status', 'pending')->count());
        $this->assertNotNull($instance->completed_at);
    }

    /** "No" and "not yet" are different answers and must stay different. */
    public function test_requesting_changes_returns_it_to_the_submitter(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->engine()->act($instance, $manager, 'changes_requested', 'Attach the receipt.');

        $instance = $instance->fresh();

        $this->assertSame('changes_requested', $instance->status);
        $this->assertSame(0, $instance->assignments()->where('status', 'pending')->count());
        $this->assertNull($instance->completed_at);
    }

    public function test_a_returned_instance_can_be_resubmitted_from_the_first_step(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $expense = $this->expense();

        $instance = $this->engine()->start($expense, $this->workflow([[]]), $this->owner);
        $this->engine()->act($instance, $manager, 'changes_requested');

        $this->engine()->resubmit($instance->fresh(), $this->owner);

        $instance = $instance->fresh();

        $this->assertSame('running', $instance->status);
        $this->assertSame(1, $instance->position);
        $this->assertSame(1, $instance->assignments()->where('status', 'pending')->count());
    }

    public function test_somebody_who_was_not_asked_cannot_approve(): void
    {
        $this->memberAt(Role::MANAGER);
        $bystander = $this->memberAt(Role::CASHIER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->expectException(RuntimeException::class);

        $this->engine()->act($instance, $bystander, 'approved');
    }

    public function test_the_same_person_cannot_approve_twice(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start(
            $this->expense(),
            $this->workflow([['quorum' => 'all']]),
            $this->owner,
        );

        $this->engine()->act($instance, $manager, 'approved');

        $this->expectException(RuntimeException::class);

        $this->engine()->act($instance->fresh(), $manager, 'approved');
    }

    public function test_a_finished_instance_refuses_further_decisions(): void
    {
        $manager = $this->memberAt(Role::MANAGER);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->act($instance, $manager, 'approved');

        $this->expectException(RuntimeException::class);

        $this->engine()->act($instance->fresh(), $manager, 'approved');
    }

    /**
     * A workflow whose first step resolves to nobody must not quietly
     * approve itself. It stops, visibly, so somebody fixes the workflow.
     */
    public function test_a_step_with_no_possible_approver_stalls_rather_than_passing(): void
    {
        $workflow = $this->workflow([['approver_mode' => 'role', 'approver_role' => Role::MANAGER]]);

        $instance = $this->engine()->start($this->expense(), $workflow, $this->owner);

        $this->assertSame('stalled', $instance->fresh()->status);
        $this->assertNotSame('approved', $instance->fresh()->status);
    }

    /** Editing a workflow must not rewrite what already happened. */
    public function test_a_decision_keeps_the_step_name_it_was_made_under(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $workflow = $this->workflow([['name' => 'Manager review']]);

        $instance = $this->engine()->start($this->expense(), $workflow, $this->owner);
        $this->engine()->act($instance, $manager, 'approved');

        $workflow->steps->first()->update(['name' => 'Something else entirely']);

        $decision = WorkflowDecision::query()->where('action', 'approved')->first();

        $this->assertSame('Manager review', $decision->step_name);
    }

    public function test_the_subject_can_find_its_own_instance(): void
    {
        $this->memberAt(Role::MANAGER);
        $expense = $this->expense();

        $this->engine()->start($expense, $this->workflow([[]]), $this->owner);

        $this->assertInstanceOf(WorkflowInstance::class, $expense->fresh()->approval());
    }

    protected function engine(): WorkflowEngine
    {
        return app(WorkflowEngine::class);
    }
}
```

- [ ] **Step 2: Run it — expect failure**

```bash
php artisan test --filter=WorkflowEngineTest
```

Expected: FAIL, `Class "App\Services\Workflow\WorkflowEngine" not found`

- [ ] **Step 3: The three remaining models**

`app/Models/WorkflowInstance.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkflowInstance extends Model
{
    use BelongsToCompany;
    use HasUlids;

    /** Terminal states. Nothing further may be recorded against these. */
    public const FINISHED = ['approved', 'rejected', 'cancelled'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(WorkflowAssignment::class);
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(WorkflowDecision::class)->orderBy('acted_at');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    public function currentStep(): ?WorkflowStep
    {
        return $this->workflow?->steps->firstWhere('position', $this->position);
    }

    /** @return array{label: string, tone: string} */
    public function state(): array
    {
        return match ($this->status) {
            'approved' => ['label' => 'Approved', 'tone' => 'positive'],
            'rejected' => ['label' => 'Rejected', 'tone' => 'negative'],
            'changes_requested' => ['label' => 'Changes requested', 'tone' => 'warning'],
            'stalled' => ['label' => 'Stalled', 'tone' => 'warning'],
            'cancelled' => ['label' => 'Cancelled', 'tone' => 'muted'],
            default => ['label' => 'In progress', 'tone' => 'neutral'],
        };
    }
}
```

`app/Models/WorkflowAssignment.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's outstanding action.
 *
 * Assignments are current; decisions are forever. This row is closed as the
 * instance advances — the permanent record of what happened is a
 * WorkflowDecision, and that is never modified.
 */
class WorkflowAssignment extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeFor(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function isOverdue(): bool
    {
        return $this->due_on !== null
            && $this->status === 'pending'
            && $this->due_on->isPast();
    }
}
```

`app/Models/WorkflowDecision.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * What somebody did, and when. The audit trail the brief requires.
 *
 * Immutable by construction: a decision is written once and never updated or
 * deleted. It carries a copy of the step's name so that renaming a workflow
 * afterwards cannot rewrite the history of what was approved under the old
 * one.
 */
class WorkflowDecision extends Model
{
    use BelongsToCompany;
    use HasUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'acted_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'workflow_instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('A workflow decision cannot be edited. Record a new one.');
        });

        static::deleting(function () {
            throw new RuntimeException('A workflow decision cannot be deleted.');
        });
    }
}
```

- [ ] **Step 4: The `Approvable` trait**

```php
<?php

namespace App\Models\Concerns;

use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * What a business model gains by being approvable.
 *
 * Deliberately thin. A module asks for an approval and reads the outcome; it
 * never touches assignments, steps or quorums. That is the whole point of
 * having one engine rather than four.
 */
trait Approvable
{
    public function workflowInstances(): MorphMany
    {
        return $this->morphMany(WorkflowInstance::class, 'subject')->latest();
    }

    /** The current run, or the most recent finished one. */
    public function approval(): ?WorkflowInstance
    {
        return $this->workflowInstances()->first();
    }

    public function isAwaitingApproval(): bool
    {
        return $this->approval()?->status === 'running';
    }

    public function isApproved(): bool
    {
        return $this->approval()?->status === 'approved';
    }
}
```

Add `use Approvable;` to `app/Models/Expense.php` for now — it is the subject
the tests drive. Other modules adopt it in their own plans.

- [ ] **Step 5: The engine**

```php
<?php

namespace App\Services\Workflow;

use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowAssignment;
use App\Models\WorkflowDecision;
use App\Models\WorkflowInstance;
use App\Models\WorkflowStep;
use App\Support\WorkflowApprovers;
use App\Support\WorkflowConditions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one place an approval happens.
 *
 * Every module asks this rather than growing its own: Documents, Procurement,
 * Expenses and HR all submit a record and read an outcome. Four approval
 * engines is the failure mode the brief names explicitly.
 */
class WorkflowEngine
{
    public function __construct(
        protected WorkflowApprovers $approvers,
        protected WorkflowConditions $conditions,
    ) {}

    public function start(Model $subject, Workflow $workflow, User $submitter): WorkflowInstance
    {
        return DB::transaction(function () use ($subject, $workflow, $submitter) {
            $instance = WorkflowInstance::create([
                'workflow_id' => $workflow->id,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'status' => 'running',
                'position' => 0,
                'started_by' => $submitter->id,
                'started_at' => now(),
            ]);

            $this->record($instance, null, $submitter, 'submitted', null, 'Submitted');

            return $this->advance($instance);
        });
    }

    /**
     * Record one person's decision, then see whether the step is finished.
     */
    public function act(
        WorkflowInstance $instance,
        User $user,
        string $action,
        ?string $comment = null,
    ): WorkflowInstance {
        return DB::transaction(function () use ($instance, $user, $action, $comment) {
            if ($instance->isFinished()) {
                throw new RuntimeException('This approval is already finished.');
            }

            $assignment = $instance->assignments()
                ->pending()
                ->where('user_id', $user->id)
                ->first();

            /*
             * Not "silently ignored". Somebody approving a record they were
             * never asked about is either a bug or an attempt, and both
             * deserve to surface rather than to no-op.
             */
            if ($assignment === null) {
                throw new RuntimeException('You have not been asked to act on this.');
            }

            $step = $assignment->step;

            $this->record($instance, $step, $user, $action, $comment, $step->name);

            $assignment->update(['status' => 'acted']);

            return match ($action) {
                'rejected' => $this->finish($instance, 'rejected'),
                'changes_requested' => $this->returnToSubmitter($instance),
                default => $this->closeStepIfSatisfied($instance, $step),
            };
        });
    }

    /** Send a returned record back round, from the beginning. */
    public function resubmit(WorkflowInstance $instance, User $submitter): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $submitter) {
            if ($instance->status !== 'changes_requested' && $instance->status !== 'stalled') {
                throw new RuntimeException('Only a returned or stalled approval can be resubmitted.');
            }

            $instance->update(['status' => 'running', 'position' => 0]);

            $this->record($instance, null, $submitter, 'submitted', null, 'Resubmitted');

            return $this->advance($instance);
        });
    }

    public function cancel(WorkflowInstance $instance, User $user, ?string $comment = null): WorkflowInstance
    {
        return DB::transaction(function () use ($instance, $user, $comment) {
            $this->record($instance, $instance->currentStep(), $user, 'cancelled', $comment, 'Cancelled');

            $instance->assignments()->pending()->update(['status' => 'superseded']);

            return $this->finish($instance, 'cancelled');
        });
    }

    /**
     * Move to the next step that actually applies, and assign it.
     *
     * Steps whose conditions do not match are stepped over rather than
     * stalled on — that is what "if the amount is over ten million" means.
     */
    protected function advance(WorkflowInstance $instance): WorkflowInstance
    {
        $steps = $instance->workflow->steps;
        $subject = $instance->subject;

        for ($position = $instance->position + 1; $position <= $steps->max('position'); $position++) {
            $step = $steps->firstWhere('position', $position);

            if ($step === null || ! $this->conditions->passes($step, $subject)) {
                continue;
            }

            $approvers = $this->approvers->resolve($step, $subject);

            /*
             * A step nobody can fill stops the instance where it is. It must
             * never be treated as satisfied: an approval that approves itself
             * because its approver left the company is worse than a stuck
             * one, because the stuck one gets noticed and fixed.
             */
            if ($approvers->isEmpty()) {
                $instance->update(['status' => 'stalled', 'position' => $position]);

                return $instance->fresh();
            }

            $instance->update(['position' => $position, 'status' => 'running']);

            foreach ($approvers as $approver) {
                WorkflowAssignment::updateOrCreate(
                    [
                        'workflow_instance_id' => $instance->id,
                        'workflow_step_id' => $step->id,
                        'user_id' => $approver->id,
                    ],
                    [
                        'status' => 'pending',
                        'due_on' => $step->due_days ? now()->addDays($step->due_days)->toDateString() : null,
                    ],
                );
            }

            return $instance->fresh();
        }

        // Nothing left to ask: every applicable step is satisfied.
        return $this->finish($instance, 'approved');
    }

    protected function closeStepIfSatisfied(WorkflowInstance $instance, WorkflowStep $step): WorkflowInstance
    {
        $assigned = $instance->assignments()->where('workflow_step_id', $step->id)->count();

        $approvals = $instance->decisions()
            ->where('workflow_step_id', $step->id)
            ->where('action', 'approved')
            ->count();

        if ($approvals < $step->requiredApprovals($assigned)) {
            return $instance->fresh();
        }

        $instance->assignments()
            ->pending()
            ->where('workflow_step_id', $step->id)
            ->update(['status' => 'superseded']);

        return $this->advance($instance->fresh());
    }

    protected function returnToSubmitter(WorkflowInstance $instance): WorkflowInstance
    {
        $instance->assignments()->pending()->update(['status' => 'superseded']);

        // No completed_at: this is "not yet", not "no". The difference is the
        // whole reason the two actions are separate.
        $instance->update(['status' => 'changes_requested']);

        return $instance->fresh();
    }

    protected function finish(WorkflowInstance $instance, string $status): WorkflowInstance
    {
        $instance->assignments()->pending()->update(['status' => 'superseded']);

        $instance->update(['status' => $status, 'completed_at' => now()]);

        return $instance->fresh();
    }

    protected function record(
        WorkflowInstance $instance,
        ?WorkflowStep $step,
        User $user,
        string $action,
        ?string $comment,
        string $stepName,
    ): WorkflowDecision {
        return WorkflowDecision::create([
            'workflow_instance_id' => $instance->id,
            'workflow_step_id' => $step?->id,
            'step_name' => $step?->name ?? $stepName,
            'user_id' => $user->id,
            'action' => $action,
            'comment' => $comment,
            'acted_at' => now(),
        ]);
    }
}
```

- [ ] **Step 6: Run — expect pass**

```bash
php artisan test --filter=WorkflowEngineTest
```

Expected: PASS, 17 tests.

- [ ] **Step 7: Regenerate the schema, run the full suite, commit**

```bash
export PATH="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"
php artisan opes:export-schema
php artisan test
```

```bash
git add app database tests
git commit -m "Approve things in one engine instead of four"
```

---

### Task 5: Delegation and reassignment

**Files:**
- Modify: `app/Services/Workflow/WorkflowEngine.php`
- Test: append to `tests/Feature/Workflow/WorkflowEngineTest.php`

- [ ] **Step 1: Write the failing test**

```php
    public function test_an_approver_can_hand_their_decision_to_somebody_else(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $deputy = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->engine()->delegate($instance, $manager, $deputy, 'On leave until Monday.');

        $instance = $instance->fresh();

        $this->assertSame(
            [$deputy->id],
            $instance->assignments()->pending()->pluck('user_id')->all(),
        );
        $this->assertSame('delegated', $instance->decisions()->where('user_id', $manager->id)->where('action', 'delegated')->first()->action);
    }

    public function test_the_delegate_can_then_approve(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $deputy = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->delegate($instance, $manager, $deputy);
        $this->engine()->act($instance->fresh(), $deputy, 'approved');

        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_the_original_approver_can_no_longer_act_after_delegating(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $deputy = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->delegate($instance, $manager, $deputy);

        $this->expectException(RuntimeException::class);

        $this->engine()->act($instance->fresh(), $manager, 'approved');
    }

    /** Delegation is a handover, not an escape: the record still says who was asked first. */
    public function test_a_delegated_assignment_remembers_where_it_came_from(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $deputy = $this->memberAt(Role::ACCOUNTANT);

        $instance = $this->engine()->start($this->expense(), $this->workflow([[]]), $this->owner);
        $this->engine()->delegate($instance, $manager, $deputy);

        $this->assertSame(
            $manager->id,
            $instance->fresh()->assignments()->pending()->first()->delegated_from,
        );
    }
```

- [ ] **Step 2: Run it — expect failure**

Expected: FAIL, `Call to undefined method … delegate()`

- [ ] **Step 3: Implement**

Add to `WorkflowEngine`:

```php
    /**
     * Hand an outstanding decision to somebody else.
     *
     * A handover, not an escape. The decision log keeps the delegation and
     * the new assignment keeps `delegated_from`, so the record still shows
     * who was asked first — otherwise "the manager approved it" would be
     * indistinguishable from "the manager passed it to a friend".
     */
    public function delegate(
        WorkflowInstance $instance,
        User $from,
        User $to,
        ?string $comment = null,
    ): WorkflowInstance {
        return DB::transaction(function () use ($instance, $from, $to, $comment) {
            if ($instance->isFinished()) {
                throw new RuntimeException('This approval is already finished.');
            }

            $assignment = $instance->assignments()->pending()->where('user_id', $from->id)->first();

            if ($assignment === null) {
                throw new RuntimeException('You have not been asked to act on this.');
            }

            $this->record($instance, $assignment->step, $from, 'delegated', $comment, $assignment->step->name);

            $assignment->update(['status' => 'delegated']);

            WorkflowAssignment::updateOrCreate(
                [
                    'workflow_instance_id' => $instance->id,
                    'workflow_step_id' => $assignment->workflow_step_id,
                    'user_id' => $to->id,
                ],
                [
                    'status' => 'pending',
                    'due_on' => $assignment->due_on,
                    'delegated_from' => $from->id,
                ],
            );

            return $instance->fresh();
        });
    }
```

Also add `delegated_to` to the decision written above by passing it through
`record()` — extend that method's signature with `?int $delegatedTo = null`
and set the column.

- [ ] **Step 4: Run — expect pass, then commit**

```bash
php artisan test --filter=WorkflowEngineTest
git add app tests
git commit -m "Let an approver hand a decision over without losing who was asked"
```

---

### Task 6: Permissions

**Files:**
- Modify: `app/Support/Permissions.php`, `database/seeders/RolePermissionSeeder.php`
- Create: `app/Policies/WorkflowPolicy.php`
- Modify: `app/Providers/AuthServiceProvider.php`
- Test: `tests/Feature/Workflow/WorkflowPermissionTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Workflow;

use App\Models\Role;
use App\Models\Workflow;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;

class WorkflowPermissionTest extends WorkflowTestCase
{
    public function test_the_catalogue_carries_the_workflow_abilities(): void
    {
        $this->assertSame(['view', 'manage'], Permissions::CATALOGUE['Workflows']);
        $this->assertTrue(Gate::has('workflows.view'));
        $this->assertTrue(Gate::has('workflows.manage'));
    }

    /**
     * Defining a workflow is defining who may commit the business to money.
     * Whoever can edit one can write themselves a path with no approver in
     * it, so this stops at the Owner and the Administrator.
     */
    public function test_only_the_owner_and_administrator_may_define_a_workflow(): void
    {
        $this->assertTrue($this->memberAt(Role::ADMINISTRATOR)->can('create', Workflow::class));
        $this->assertFalse($this->memberAt(Role::MANAGER)->can('create', Workflow::class));
        $this->assertFalse($this->memberAt(Role::ACCOUNTANT)->can('create', Workflow::class));
    }

    public function test_a_manager_can_still_see_what_the_rules_are(): void
    {
        $this->assertTrue($this->memberAt(Role::MANAGER)->can('viewAny', Workflow::class));
    }

    public function test_approving_does_not_require_a_workflow_permission(): void
    {
        // Being asked to approve IS the permission. Requiring a second one
        // would mean an approver assigned by the engine could not act.
        $manager = $this->memberAt(Role::MANAGER);
        $instance = app(\App\Services\Workflow\WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        $this->assertFalse($manager->can('manage', Workflow::class));

        app(\App\Services\Workflow\WorkflowEngine::class)->act($instance, $manager, 'approved');

        $this->assertSame('approved', $instance->fresh()->status);
    }
}
```

- [ ] **Step 2: Run it — expect failure.**

- [ ] **Step 3: Add to the catalogue**

```php
        /*
         * Who may define an approval path — not who may approve. Being asked
         * to approve is itself the permission; requiring a second one would
         * mean an approver the engine assigned could not act.
         *
         * `manage` stops at the Owner and the Administrator because whoever
         * can edit a workflow can write themselves a path with no approver in
         * it, which is the same as being able to spend the money.
         */
        'Workflows' => ['view', 'manage'],
```

- [ ] **Step 4: Seed it** — grant `Workflows => ['view']` to manager,
      accountant and read-only. `manage` comes only from the `*` grants.

- [ ] **Step 5: The policy** — identical in shape to `DepartmentPolicy`:
      `group()` returns `'workflows'`; `create`, `update` and `delete` all
      require `manage`.

- [ ] **Step 6: Register it, run, commit.**

---

### Task 7: My Actions

**Files:**
- Create: `app/Livewire/Workflow/Inbox.php` + view
- Modify: `routes/web.php`
- Test: `tests/Feature/Workflow/WorkflowInboxTest.php`

This is §34 of the master spec, and the reason the whole engine is worth
having: one list of everything waiting on you, across every module.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Workflow;

use App\Livewire\Workflow\Inbox;
use App\Models\Role;
use App\Services\Workflow\WorkflowEngine;
use Livewire\Livewire;

class WorkflowInboxTest extends WorkflowTestCase
{
    public function test_the_inbox_lists_what_is_waiting_on_you(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        app(WorkflowEngine::class)->start($this->expense(), $this->workflow([[]]), $this->owner);

        Livewire::test(Inbox::class)->assertSee('Generator fuel');
    }

    public function test_it_does_not_list_what_is_waiting_on_somebody_else(): void
    {
        $this->memberAt(Role::MANAGER);
        $bystander = $this->memberAt(Role::CASHIER);
        $this->actingAs($bystander);

        app(WorkflowEngine::class)->start($this->expense(), $this->workflow([[]]), $this->owner);

        Livewire::test(Inbox::class)->assertDontSee('Generator fuel');
    }

    public function test_approving_from_the_inbox_advances_the_workflow(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        Livewire::test(Inbox::class)->call('approve', $instance->id);

        $this->assertSame('approved', $instance->fresh()->status);
    }

    public function test_an_item_leaves_the_inbox_once_it_is_dealt_with(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        $instance = app(WorkflowEngine::class)
            ->start($this->expense(), $this->workflow([[]]), $this->owner);

        app(WorkflowEngine::class)->act($instance, $manager, 'approved');

        Livewire::test(Inbox::class)->assertDontSee('Generator fuel');
    }

    public function test_an_overdue_item_is_marked(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        app(WorkflowEngine::class)->start(
            $this->expense(),
            $this->workflow([['due_days' => 3]]),
            $this->owner,
        );

        $this->travel(5)->days();

        Livewire::test(Inbox::class)->assertSee('Overdue');
    }
}
```

- [ ] **Step 2: Run it — expect failure.**

- [ ] **Step 3: The component** — query
      `WorkflowAssignment::pending()->for(auth()->user())->with('instance.subject', 'step')`,
      paginated, never unbounded (§60). Expose `approve`, `reject`,
      `requestChanges` and `delegate`, each calling the engine and each
      re-authorising through the engine's own assignment check rather than
      re-deriving one.

- [ ] **Step 4: The view** — reuse `x-ui.panel` and the existing card idiom.
      One row per item: what it is, which step, who asked, when it is due.

- [ ] **Step 5: Route** — `/actions`, `->middleware('auth')` only. There is no
      permission gate: being assigned IS the permission.

- [ ] **Step 6: Run, full suite, commit.**

---

### Task 8: Documentation

- [ ] **Step 1: `docs/workflows.md`** — the model in one picture, how a module
      makes itself approvable, how approver modes resolve, how conditions are
      written, and the rule that a step with no approver stalls.
- [ ] **Step 2: `docs/API.md`** — a section for instances and decisions.
- [ ] **Step 3: `docs/GAP-ANALYSIS.md`** — ERP #20 from **C** to **A**;
      note that `document_approvals` still exists and why.
- [ ] **Step 4: Tick 1.2 in the roadmap.**
- [ ] **Step 5: Commit.**

---

## Testing plan

| Risk | Test |
|---|---|
| An approval approves itself when its approver has left | `test_a_step_with_no_possible_approver_stalls_rather_than_passing` |
| Somebody approves a record they were never asked about | `test_somebody_who_was_not_asked_cannot_approve` |
| One person satisfies a two-person quorum | `test_the_same_person_cannot_approve_twice` |
| Editing a workflow rewrites past approvals | `test_a_decision_keeps_the_step_name_it_was_made_under` |
| "Rejected" and "needs changes" collapse into one outcome | `test_rejection_stops_the_instance_immediately`, `test_requesting_changes_returns_it_to_the_submitter` |
| A conditional step stalls instead of being skipped | `test_a_step_whose_condition_fails_is_skipped` |
| An admin-typed condition executes as code | `test_an_unknown_operator_fails_closed` |
| Delegation hides who was originally asked | `test_a_delegated_assignment_remembers_where_it_came_from` |
| Approving requires a permission the approver lacks | `test_approving_does_not_require_a_workflow_permission` |
| Cross-tenant leakage | Every new table carries `company_id` and the tenant scope |
| MySQL rejects a key SQLite accepted | `php artisan opes:export-schema` before commit |
