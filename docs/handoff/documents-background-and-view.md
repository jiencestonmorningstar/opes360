# Documents completion — background processing, full view, offline (wave 5-6)

Covers items 5-6 of `docs/superpowers/plans/2026-08-17-documents-completion.md`:
§60 background processing, §53 full document view, §50-51 offline.

## 1. Background bundling (§60) — built

`DocumentBundles::zipDocuments()`/`zipPackage()` are unchanged: they remain
the fully synchronous packager, and package downloads still call them
directly, exactly as before.

New: `DocumentBundles::request(Collection $documents, User $actor)`, the
entry point `BulkActions::zip()` now calls instead. It decides, per
selection:

- **`config('queue.default') === 'sync'`** (this app's default, and shared
  hosting without a worker) — always returns a path, built inline, in the
  same request. No behaviour change from before this feature existed.
- **A real queue configured, and the selection is "large"** — creates a
  `BusinessDocumentBundle` row (`pending`), dispatches
  `App\Jobs\BuildDocumentBundle`, and returns the row. The Livewire action
  (`Papers\Index::bulkDownload`) tells the user it's building in the
  background instead of blocking the click.
- **A real queue configured, but the selection is small** — still returns a
  path built inline. Queueing a three-file ZIP would just add latency for
  no benefit.

"Large" (`DocumentBundles::LARGE_FILE_COUNT` / `LARGE_BYTES`, argued in the
class docblock): more than 20 files, or more than 20 MB summed from the
`media` table. Both are argued thresholds — there's no production traffic to
tune them against yet — but they only bite when a queue is actually
configured, so getting them slightly wrong costs a slower request, not a
broken one.

`BuildDocumentBundle` (in `app/Jobs`) carries ids, not models — same reason
`DeliverWebhook` does: a job that waits on the queue can run against a row
that changed underneath it. It re-enters `CurrentCompany::as()` so the
company-scoped queries inside `DocumentBundles`/`Media` behave as they would
in a request, builds the ZIP, stores it on the `documents` disk under
`bundles/{company}/{bundle}.zip`, marks the bundle `ready`, and notifies the
requester via `DocumentBundleReadyNotification` (mail + database, same shape
as `DocumentCommentMention`). A job failure marks the bundle `failed` with
the error message rather than leaving it stuck `pending` forever.

`Papers\Index::downloadBundle($bundleId)` is the fetch side — a Livewire
action, not a new route (routes/* was off-limits for this pass), so it rides
the same authentication as the rest of the workspace and re-checks the
bundle belongs to the current company scope before streaming it.

**Not built**: a "downloads" panel UI listing a user's pending/ready
bundles. `downloadBundle()` exists and is tested directly; wiring a bell/
panel that lists `BusinessDocumentBundle` rows for the current user is
UI-only and cheap, but was out of scope for this pass — flagged below.

Tests: `tests/Feature/Documents/DocumentBundleQueueTest.php` (6 cases —
sync always inline, small selection never queued even with a real queue,
large selection defers, byte threshold alone triggers it, the job itself
builds+notifies, `BulkActions::zip` reaches the same decision).

## 2. Search reindex on upload (§60) — built

`SearchIndexObserver` previously called `GlobalSearch::index()`/`forget()`
directly from `saved`/`deleted`/`restored`/`forceDeleted` — no `ShouldQueue`
anywhere in that path. It now dispatches `App\Jobs\IndexSearchEntry`
(carries `modelClass` + `modelId` + `companyId`, not the model, for the same
staleness reason as above) instead.

Under `sync`, a `ShouldQueue` dispatch runs inline in the same request in
call order — Laravel's own behaviour, nothing this job does — so this is
functionally identical to before on an unqueued install. With a queue
configured, indexing genuinely moves off the request. The existing
`GlobalSearchTest` suite passes unchanged, proving the observed behaviour
didn't shift under `sync`, and `DocumentBundleQueueTest`'s
`Queue::assertNotPushed(BuildDocumentBundle::class)` case incidentally
proves `IndexSearchEntry` really does hit the fake queue now (a document
upload triggers `saved`, which queues one).

A model with no `company_id` yet is skipped in the observer before
dispatch, rather than queued and rejected inside the job — `GlobalSearch::
index()` would refuse it either way, so this just avoids a pointless round
trip through the queue.

## 3. Full document view audit (§53)

Spec's side panel: **details, related records, comments, versions,
activity, permissions, workflow, attachments**.

**Already present, just not on `Papers/Show.php`:**
- Versions, comments, and a soft edit-lock indicator — all on
  `Papers/Edit.php` (the rich editor screen), added in the rich-editor wave.
  That screen is where writing/collaboration happens; `Show.php` is the
  read/act screen and had none of this.
- Retention/legal-hold, shares, signatures — all real, working services
  (`DocumentSharing`, retention flags on `BusinessDocument`, the
  `signatures()`/`shares()` relations) exposed over `/api/v1/library/*`
  for API/mobile consumers, but never rendered anywhere in the Livewire UI.
- Activity — `DocumentActivity::timeline()` existed and was fully tested,
  called from nowhere in the UI.

**Built this pass** (`app/Livewire/Papers/Show.php` +
`resources/views/livewire/papers/show.blade.php`): a "Details" panel
(version count, comment count, legal-hold badge, share count, signature
progress) and, gated behind `papers.manage` since it can reveal who touched
a restricted document, a "Recent activity" panel (last 8 events from
`DocumentActivity::timeline()`). All read-only wiring onto services that
already existed — no new backend.

Tests: `tests/Feature/Documents/ShowSidePanelTest.php`.

**Genuinely still missing, not built (needs real new infra or scope, not a
one-file wire-up):**
- **Related records** — nothing in this codebase currently answers "what
  ERP record is this document about" as a queryable relation from the
  document side. `DocumentFieldRegistry` (built in an earlier wave of this
  plan) is the generalised link *into* a record, but there's no reverse
  index for "which sale/contract/employee does this trace back to" to
  render as a panel section without building that index first.
- **Permissions panel** — who can see/edit this specific document beyond
  the role-based policy check. The policy logic exists
  (`BusinessDocumentPolicy`); a UI listing "these are the people/roles with
  access" is a new screen, not a wire-up.
- **Workflow panel** — approval-rule status is checked
  (`submitForApproval`/`isAwaitingApproval` already show inline), but a
  dedicated workflow-state visualisation (steps, current approver, history)
  beyond the one-line badge already on `Show.php` doesn't exist.
- **Attachments** — a document can *be* a file (via `DocumentFiler`/`Media`)
  but cannot currently *carry* secondary attachments of its own. That's a
  new relation and upload UI, not a missing render.
- **Downloads panel** — see §1 above; the backend (`BusinessDocumentBundle`)
  is ready, the panel listing them for a user isn't built.

Recommend these five as their own follow-up items, not folded into this
pass — each is a small-to-medium feature in its own right, not a missing
`@if` block.

## 4. Offline (§50-51) — assessed, deferred

`App\Services\SyncEngine::ENTITIES` covers exactly `contact`, `item`,
`document` (the sales-document model, `App\Models\Document`), and `payment`.
`App\Models\BusinessDocument` (Papers — everything this plan wave has been
building against) is not in that list, and neither are comments or folder
filing.

**Recommendation: defer**, for reasons specific to this feature rather than
general offline aversion:

1. **The sync protocol is JSON-envelope shaped.** Every entity in
   `SyncEngine::ENTITIES` is a row of scalar fields. A Papers document is
   frequently a stored *file* (`DocumentFiler`/`Media`, up to 25 MB) — the
   envelope protocol (`pull()`/`push()` over `sync_receipts`) has no binary
   transfer story at all. Wiring it in "minimally" would mean either (a)
   silently excluding uploaded documents from offline sync while pretending
   composed/text documents work, which is a worse user experience than no
   offline support (a feature that works for some documents and silently
   drops others), or (b) building binary-envelope support, which is real
   new infrastructure the plan's own rules said not to invent here.
2. **`BusinessDocument` has no `sync_sequence` column or the offline-lease
   machinery `Document` has** (`device_id`, `synced_at`, number leasing via
   `DocumentNumbers`). Every one of those would need adding and reasoning
   through — a schema change, not a wiring change.
3. **The usage pattern argument, asked for explicitly:** `Document` (sales
   paper — invoice/receipt/waybill) is offline-critical because it is
   created *at the point of sale*, often on a phone at a customer's
   location with no signal — that is the offline-first PWA's whole reason
   to exist. Papers (contracts, uploaded certificates, generated letters)
   are composed or filed at a desk, ahead of time, with a connection. The
   plan's own hint ("usually not edited in poor connectivity the way a sale
   is") holds up under this audit: nothing in `Papers/Edit.php`'s workflow
   (soft lock, heartbeat poll, autosave) assumes or benefits from offline
   operation, and the file-upload path (`DocumentFiler::upload`) requires a
   multipart request that has no offline story regardless of `SyncEngine`.

If this is revisited, the shape that would make sense is narrower than
"add BusinessDocument to `ENTITIES`": queue *metadata-only* actions (filing
into a folder, tagging, a comment) for documents already synced/cached
locally, explicitly excluding new uploads and binary content — closer to a
"pending actions" outbox than a full sync entity. That is a genuinely new,
separable feature, not an extension of the four rows already in
`SyncEngine::ENTITIES`.

## Verification

```
php artisan test --filter="Bundle|Search|Document|Sync"
vendor/bin/pint --dirty
```

All of `DocumentBundleTest`, `BulkActionsTest`, `GlobalSearchTest`,
`DocumentBundleQueueTest`, `ShowSidePanelTest` pass; the wider `Document`
filter also runs every other Documents suite untouched by this pass to
confirm nothing regressed.

## Files

- `app/Jobs/BuildDocumentBundle.php` (new)
- `app/Jobs/IndexSearchEntry.php` (new)
- `app/Models/BusinessDocumentBundle.php` (new)
- `app/Notifications/DocumentBundleReadyNotification.php` (new)
- `database/migrations/2026_09_17_000001_create_business_document_bundles_table.php` (new)
- `app/Services/Documents/DocumentBundles.php` (additive: `request()`, `isLarge()`, thresholds)
- `app/Services/Documents/BulkActions.php` (`zip()` now returns `string|BusinessDocumentBundle`)
- `app/Search/SearchIndexObserver.php` (dispatches `IndexSearchEntry` instead of calling `GlobalSearch` inline)
- `app/Livewire/Papers/Index.php` (`bulkDownload()` handles both return shapes; new `downloadBundle()`)
- `app/Livewire/Papers/Show.php` + `resources/views/livewire/papers/show.blade.php` (Details + Recent activity panels)
- `tests/Feature/Documents/DocumentBundleQueueTest.php` (new)
- `tests/Feature/Documents/ShowSidePanelTest.php` (new)
