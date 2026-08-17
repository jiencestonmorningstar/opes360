# Documents §5 — creation routes: handoff

Implements plan item 1 of `docs/superpowers/plans/2026-08-17-documents-completion.md`:
four ways to end up with a new `BusinessDocument`, all going through
`App\Services\DocumentComposer` (never a parallel creator).

Routes/* is owned by someone else per this task's instructions, so nothing in
`routes/web.php` was touched. One route needs adding by hand — see below.

## 1. Duplicate

- `App\Services\DocumentComposer::duplicate(BusinessDocument $source, User $user): BusinessDocument`
  — fresh draft: same template/title(prefixed "Copy of ")/recipient/fields/body/kind/language;
  status reset to draft; reference, content_hash, folder_id, department_id,
  owner_id, security, tags, expires_on all reset to null (new
  `BusinessDocument::create()`, so nothing is inherited unless listed).
  Comments/versions/shares are relations of the *old* row's id and are never
  touched.
- UI: `App\Livewire\Papers\Show::duplicate()` (button on the paper's
  action rail, gated `@can('papers.create')`) and
  `App\Livewire\Papers\Index::duplicate(string $paperId)` (row action,
  re-reads the document through `readableBy` before authorizing, so a
  document outside the caller's visibility can't be duplicated by id
  guessing). Both redirect to `papers.edit` on the new draft.

**No new route needed** — both actions call an existing route (`papers.edit`)
on the new document's id.

## 2. From an ERP record (Contact, Contract)

- New service: `App\Services\Documents\RecordDocumentComposer`.
  - `contextKeyFor(Model $record): ?string` — Contact → `customer`,
    Contract → `contract`. Add more record classes to `$contextKeys` to add
    more record types.
  - `composeDraft(Model $record, string $templateKey, array $fields, string $title, Company $company, User $user): BusinessDocument`
    — merges through `DocumentComposer::merge()` with the record in
    `DocumentFieldRegistry` context, creates the draft, links it back via
    `DocumentLinker::attach($document, $record, 'about', $user)`.
  - Registers the `contract` field provider (`contract.title`,
    `contract.type`, `contract.value`) lazily on first use, because
    `AppServiceProvider` (where `customer`/`employee`/`project` are
    registered) is out of this task's ownership. `customer` already existed
    and needed no new registration.

**Route needed** — nothing wires a "New document" button on a Contact or
Contract screen yet, because a route/UI entry point wasn't something this
task could add to `routes/*`. Suggested route (Compose already accepts
`?template=`; it would need two new optional params, `recordType`/`recordId`,
threaded into `Compose::mount()` and `Compose::save()` to call
`RecordDocumentComposer::composeDraft()` instead of the current
`BusinessDocument::create()` when present):

```php
// alongside the existing papers.create line in routes/web.php:
Route::get('/library/new/{template}/for/{recordType}/{recordId}', PapersCompose::class)
    ->middleware('can:papers.create')
    ->name('papers.create.for');
```

Until that param-handling is added to `Compose`, call
`RecordDocumentComposer::composeDraft()` directly from a Contact/Contract
screen's own Livewire action (mirroring `Papers\Show::duplicate()`) rather
than routing through Compose — the service does not require the route to
exist.

## 3. From a workflow step

- New step type `compose_document`, added to `WorkflowStep::TYPES`
  (additive — review/approval/signature/task unchanged).
- New nullable columns on `workflow_steps`: `compose_template`,
  `compose_link_role` — migration
  `database/migrations/2026_09_18_000001_add_compose_document_config_to_workflow_steps.php`.
- New handler: `App\Services\Workflow\ComposeDocumentStep::handle()` —
  composes through `DocumentComposer::merge()` + `BusinessDocument::create()`,
  seeds `DocumentFieldRegistry` context via `RecordDocumentComposer` when the
  workflow's subject has a registered context key, links the draft to the
  subject via `DocumentLinker`.
- `App\Services\Workflow\WorkflowEngine::advance()` gained one additive
  branch: when the step reached has `type === 'compose_document'`, it calls
  the handler and advances past the step immediately (no approver
  assignment — nobody is asked to decide anything). Every other step type's
  code path is untouched; see
  `WorkflowComposeDocumentStepTest::test_an_ordinary_approval_step_still_behaves_exactly_as_before`.

No route change needed — this fires inside `WorkflowEngine`, not through a
web request.

## 4. From an automation rule

- New `AutomationRule::ACTIONS` entry: `compose_document` (additive).
- `App\Services\Automation\ActionRunner::composeDocument()` — reads
  `action_config.template` (required), optional `action_config.title` and
  `action_config.link_role`; resolves `DocumentFieldRegistry` context via
  `RecordDocumentComposer::contextKeyFor($event->subject)` (empty context,
  not an error, when the subject has none registered); composes through
  `DocumentComposer::merge()`; links via `DocumentLinker`.
- A rule missing `template` throws, which the existing
  `AutomationRule`/emitter machinery already swallows per-rule (proven
  generically in `AutomationTest`, and specifically for this action in
  `ComposeDocumentActionTest::test_composing_without_a_template_key_never_leaves_a_partial_document`).

No route change needed — configured on the existing automation rules screen,
which already lists `AutomationRule::ACTIONS` for its action dropdown.

## Gate used everywhere

`papers.create` — on `Papers\Show::duplicate()`, `Papers\Index::duplicate()`,
and is the gate `RecordDocumentComposer` callers are expected to check before
calling it (the service itself doesn't gate, the same way
`DocumentComposer::merge()`/`issue()` don't — authorization is the Livewire
action's job, consistent with the rest of Papers).

## Files touched

- `app/Services/DocumentComposer.php` — added `duplicate()`.
- `app/Services/Documents/RecordDocumentComposer.php` — new.
- `app/Services/Workflow/ComposeDocumentStep.php` — new.
- `app/Services/Workflow/WorkflowEngine.php` — one additive branch in `advance()`.
- `app/Services/Automation/ActionRunner.php` — one additive `match` arm + `composeDocument()`.
- `app/Models/AutomationRule.php` — one additive `ACTIONS` entry.
- `app/Models/WorkflowStep.php` — one additive `TYPES` entry.
- `database/migrations/2026_09_18_000001_add_compose_document_config_to_workflow_steps.php` — new, additive columns.
- `app/Livewire/Papers/Show.php`, `app/Livewire/Papers/Index.php` — duplicate actions.
- `resources/views/livewire/papers/show.blade.php` — Duplicate button.
- Tests: `tests/Feature/Documents/DocumentCreationRoutesTest.php`,
  `tests/Feature/Documents/WorkflowComposeDocumentStepTest.php`,
  `tests/Feature/Automation/ComposeDocumentActionTest.php`.
