# Documents event stream + extension points (§54/58/59)

## What changed

### Event stream — every real lifecycle moment now announces itself

Audited every `document.*` name already in `DomainEvents::CATALOGUE` against
`app_path()`. Five were catalogued from the brief but had no emission site
at all (a live instance of the exact `hr.*` drift class the 2026-08 audit
found); five more real moments existed but weren't catalogued. All fixed,
additively, at each moment's one true call site:

| Event | Call site |
|---|---|
| `document.created` | `BusinessDocument::booted()`, the `created` hook |
| `document.version.created` | `DocumentVersioner::snapshot()` — every version, including the initial one |
| `document.version.restored` *(new)* | `DocumentVersioner::restore()` |
| `document.published` | `DocumentComposer::issue()` |
| `document.voided` *(new)* | `DocumentComposer::void()` |
| `document.shared` | `DocumentSharing::create()` |
| `document.commented` *(new)* | `DocumentComments::post()` |
| `document.signature.requested` | `DocumentSignatureRequests::request()` |
| `document.legal_hold.placed` / `.lifted` *(new)* | `DocumentRetention::placeLegalHold()` / `liftLegalHold()` |
| `document.expired` | New `expiredDocuments()` sweep in `RemindOutstandingActions` (`opes:remind-actions`), announced once ever per document (not daily, unlike `document.expiring`) — expiry is a one-time transition, not a standing reminder |
| `document.submitted` / `.approved` / `.rejected` / `.changes.requested` | Already worked, via `TranslateDocumentWorkflowEvents` restating `workflow.*` |
| `document.review.requested` *(now real)* | `WorkflowEngine::advance()` now actually emits `workflow.step.assigned` (catalogued since the engine's beginning but never raised anywhere — a second latent instance of the drift bug, this one in the shared workflow engine, not Documents), translated the same way as the other workflow.* events |

Already-working and left untouched: `document.updated` (`BulkActions`), `document.archived` (`BulkActions`), `document.signed` (`DocumentSignatureRequests`), `document.expiring` / `document.signature.overdue` (existing `RemindOutstandingActions` sweeps).

`DomainEvents::CATALOGUE['document']` gained a doc comment explaining the five
new names — no existing name was renamed or removed.

### Extension point: `DocumentTypeRegistry`

`app/Services/Documents/DocumentTypeRegistry.php` — the same shape as
`DocumentFieldRegistry`, but static rather than a bound singleton: a module
calls `DocumentTypeRegistry::register($key, $label, $group)` from its own
service provider's `boot()`, and `DocumentKinds::all()` folds the result in
without Documents ever importing the module. Static because
`DocumentKinds` itself has no container dependency and is called from
scopes/views with no reason to resolve one first — a singleton binding
would have meant touching `AppServiceProvider`, which is out of scope here
by design. A built-in key always wins a collision (`builtIn() +
DocumentTypeRegistry::all()`), so a registered kind can't silently
reassign what a built-in one means. `DocumentTypeRegistry::flush()` exists
for test isolation only.

### Automation reach — confirmed, not rebuilt

- **Triggers**: `RunAutomationRules::handle()` matches on `$event->name` with
  no module-specific wiring — every `document.*` event already reaches
  automation rules as a trigger with no further work needed.
- **Actions**: `compose_document` as an automation action has **not landed**
  (no reference anywhere in `app/`). Depends on the sibling creation-routes
  work (see `docs/handoff/documents-creation-routes.md`). Not built here —
  noted as a dependency, not blocked on.

## Tests

- `tests/Feature/Documents/DocumentEventStreamTest.php` — drives each new/completed
  call site and asserts the matching event actually dispatches (dynamic proof).
- `tests/Feature/Automation/DomainEventCatalogueParityTest.php` — added
  `test_the_document_events_are_actually_emitted_somewhere()`, the
  parity-shaped test requested: every catalogued `document.*` name has an
  emission site, either a literal `emitDomainEvent('document.x'` or (for the
  four workflow-translated ones) the corresponding `workflow.*` literal that
  `WorkflowEngine::announce()` forwards dynamically.
- `tests/Feature/Documents/DocumentTypeRegistryTest.php` — registration,
  built-in-wins-collision, and graceful fallback for an unknown kind.

`php artisan test --filter="DomainEvent|Automation|Document"` passes for all
of the above (19/19 on the three new files). Two pre-existing failures in
that filter — `DocumentCreationRoutesTest` and `WorkflowComposeDocumentStepTest`
(`contracts.starts_on` NOT NULL) — belong to the sibling creation-routes
agent's in-flight work, not this change; confirmed unrelated by re-running
before/after this branch's edits.

## Files touched

- `app/Support/DomainEvents.php` (additive)
- `app/Models/BusinessDocument.php`
- `app/Services/DocumentComposer.php`
- `app/Services/Documents/DocumentVersioner.php`, `DocumentSharing.php`,
  `DocumentComments.php`, `DocumentSignatureRequests.php`,
  `DocumentRetention.php`
- `app/Services/Documents/DocumentTypeRegistry.php` (new)
- `app/Support/DocumentKinds.php`
- `app/Services/Workflow/WorkflowEngine.php` (one additive `announce()` call)
- `app/Listeners/TranslateDocumentWorkflowEvents.php` (one map entry)
- `app/Console/Commands/RemindOutstandingActions.php`
- New tests listed above

`pint` clean on every touched file.
