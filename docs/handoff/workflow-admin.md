# Handoff — workflow administration screens

Two Livewire screens on which a business defines its own approval paths. Until
now the engine was complete and data-driven but had no screen behind it, so a
business could only ever use what `DefaultWorkflows` seeded for it.

## Routes I need

`routes/web.php`, inside the authenticated `web` group (alongside `/actions`):

```php
use App\Livewire\Workflow\Edit as WorkflowEdit;
use App\Livewire\Workflow\Index as WorkflowIndex;

Route::get('/workflows', WorkflowIndex::class)->name('workflows');
Route::get('/workflows/{workflow}', WorkflowEdit::class)->name('workflows.edit');
```

No route middleware gate is needed and none should be added: both components
call `Gate::authorize('workflows.view')` in `mount()` and
`Gate::authorize('workflows.manage')` on every method that writes. `{workflow}`
binds on the ULID primary key as normal.

## Navigation entry

Under **Settings**, next to Notification rules — the two are the same kind of
thing and behind the same abilities:

```php
['label' => 'Approval paths', 'route' => 'workflows', 'icon' => 'check-circle', 'can' => 'workflows.view'],
```

Both screens render with `'active' => 'settings'`.

## What was built

| File | What it is |
|---|---|
| `app/Livewire/Workflow/Index.php` + `resources/views/livewire/workflow/index.blade.php` | Every path, grouped by what it approves. Create, duplicate, switch on/off, set as default, remove. |
| `app/Livewire/Workflow/Edit.php` + `resources/views/livewire/workflow/edit.blade.php` | One path: name, subject, and its ordered steps. Add, reorder, edit, remove. Per step: name, type, approver mode, quorum, conditions, due days. |
| `app/Services/Workflow/WorkflowVersioning.php` | Copy-on-write freezing, and the empty-approver warnings. |
| `app/Support/WorkflowSubjects.php` | Class name → words a business recognises, and the fields a condition may be written about. |
| `database/migrations/2026_09_12_000100_add_archived_from_to_workflows.php` | One nullable `workflows.archived_from_id`. |
| `tests/Feature/Workflow/WorkflowAdminTest.php` | 15 tests. The routes above are registered in its `setUp` so it does not depend on the wiring. |

Additive edits only, to `app/Models/Workflow.php` (`archivedFrom()`,
`isArchivedVersion()`, `scopeDefinitions()`, and `defaultFor()` now excludes
frozen copies) and `app/Support/WorkflowConditions.php` (`OPERATORS` made
`public` so the condition editor offers exactly what the engine evaluates —
a second list in the form would drift, and the drift is invisible because an
unknown operator fails closed and the step is silently skipped).

## Rule 4 — editing versus approvals already in flight

**I versioned it, by copy-on-write, and refused nothing.**

The danger is real and worse than it looks. The engine walks
`workflow->steps` by position every time it advances, and an assignment points
at a step row with `cascadeOnDelete`. So editing in place would:

- delete a step and take its assignments with it — the people who were asked
  simply stop being asked, with nothing anywhere to show it happened;
- reorder steps and move a running instance's `position` onto a different step,
  so an approval repeats one somebody already signed or skips one nobody did;
- insert a step at the front and push every later step up one, identically.

Refusing the edit was the alternative and it is the wrong one: a business
discovers a workflow is wrong *because* something is stuck in it, so "you
cannot fix this until the thing it is blocking finishes" is a deadlock dressed
up as a safety rule.

So before any change to the steps — add, edit, remove, reorder, and before
deleting the workflow — `WorkflowVersioning::freeze()` copies the current
definition into a new `Workflow` row (`archived_from_id` set, inactive, never
default), copies every step, moves the unfinished instances onto the copy and
re-points their assignments at the copied steps. They finish under exactly the
rules they started under. The workflow being edited keeps its own id, name,
default flag and URL, so everything pointing at it still works and the next
submission gets the new rules.

Two deliberate non-actions:

- **Decisions are not moved.** They already carry a copy of the step name and
  their step id nulls rather than cascades, because the schema decided long
  before this screen existed that an edit must not rewrite what already
  happened. Moving them would be rewriting it.
- **Switching a path off does not freeze anything.** `is_active` decides which
  workflow a *new* submission picks up; stopping a running one would strand
  whoever was asked to sign.

Frozen copies are excluded from every list and every lookup via
`Workflow::definitions()`, and `Edit` 404s on one — they are a record of rules
still being applied, not a path anybody may choose or rewrite.

## The other rules

1. **Approvers are named, not resolved.** The mode select lists role,
   department, owner, submitter's manager and creator before `user`, which is
   labelled "avoid unless you mean it", and choosing it prints "This step will
   stop working the day they leave, and it will stop silently." The paragraph
   under the select says why in plain words. Only the field belonging to the
   chosen mode is saved, so a stale user id can never survive a switch to role.
2. **A step nobody can fill is called out, never blocked.** Empty roles,
   department without a manager, and a mode with nothing chosen each produce a
   warning — on the step, on the list, and beside the save. The save always goes
   through: a business may be writing the path for a job it is about to
   advertise.
3. **Conditions are data.** Field from the subject's real columns, operator
   from `WorkflowConditions::OPERATORS`, value stored numeric when it looks
   numeric (a threshold saved as the string `"10000000"` compares wrong against
   a five-figure total, and the symptom is a limit that silently lets
   everything through).
5/6. **`workflows.view` reads, `workflows.manage` writes.** `Gate::authorize`
   in every method, `@can` around every control. No new abilities.

## Two things the engine made awkward

- **`Workflow::defaultFor()` only returns active workflows**, so "make this the
  default" has to switch it on at the same time or the business gets a default
  that answers nothing. `makeDefault` does both.
- **A workflow with no steps approves everything instantly** (`advance()`
  finishes as `approved` on an empty step list). A new path is therefore created
  inactive and cannot be switched on or made default until it has a step. This
  is the one silent way an admin screen could turn the whole approval engine
  into a rubber stamp.

## One thing for you

`tests/Unit/InstallSchemaIsCurrentTest` fails until
`php artisan opes:export-schema` is run, because my migration is not yet in
`database/schema/opes360-install.sql`. It is already failing for the same
reason on other in-progress migrations in this worktree (`leads`,
`crm_activities`, the vacancy tables), so it wants running once at the end
rather than per branch. Every test under `--filter=Workflow` passes: 110.
