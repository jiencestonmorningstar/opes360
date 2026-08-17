# Rich document editor v1 — handoff

The "many people work on a document" capability, honestly scoped: rich
editing + autosave + version snapshots + soft edit-locking + the existing
comments + field chips (§3.1/§3.2 of the master spec — see below).
**Not** keystroke-realtime — that upgrade path is at the bottom.

## Field chips (2026-08-17 addition)

Typing `@` in the editor opens a picker of every field the document's linked
ERP record can offer (`customer.name`, `employee.name`, `project.name`,
`contract.title` — whatever `DocumentComposer::availableTokens()` reports);
choosing one inserts an immutable chip, deletable only as a whole unit.

- **Node/extension**: `resources/js/editor/field-token.js` — a Tiptap inline
  atom node (`fieldToken`) rendered as `<span data-token="…">label</span>`,
  plus a `@`-triggered `@tiptap/suggestion` picker.
- **Server resolution**: `DocumentComposer::resolveFieldChips()`, called from
  `toHtml($body, $document)` — every time a draft's HTML body is rendered,
  each chip's text is re-fetched from `DocumentFieldRegistry` against the
  document's linked "about" record. A chip is "living" (§3.3): open the
  document tomorrow after the customer's name changed, and the chip shows
  the new name, not what was typed when it was inserted.
- **Context source**: `DocumentComposer::liveContextFor()` reads the
  document's `about`-role relation via `DocumentLinker` and maps its class to
  a registry prefix (`Contact` → `customer`, `Employee` → `employee`,
  `Project` → `project`, `Contract` → `contract` — same map
  `RecordDocumentComposer` uses for compose-time context, duplicated rather
  than shared to avoid a circular constructor dependency between the two
  classes). A document with no such link, or one about a record type with no
  registered prefix, offers no chips to insert and resolves none that exist.
- **Sanitizer**: `HtmlSanitizer` now allows `span` only when it carries
  `data-token` — a bare `<span>` (anything the toolbar or a paste could
  produce that isn't a chip) still unwraps exactly as before this existed.
- **Deliberately NOT resolved live**: the print, e-signature, and external
  share paths (`PrintController`, `SignatureController`,
  `DocumentShareController`, `DocumentBundles`) still call `toHtml($body)`
  with no `$document` — resolving a chip there could show a value that
  postdates whatever was signed or hashed, which would undermine the
  existing issuance snapshot guarantee (§3.3's "Rendered Text Engine"). Same
  reasoning gates `Show.php`: an issued document's chips stay frozen at
  whatever text they last rendered before issuance; only a draft re-resolves.
  Actually freezing a chip's token into permanent plain text at the moment of
  issuance — so the immutability is enforced rather than merely un-triggered
  — is not yet built; see the roadmap plan
  (`docs/superpowers/plans/2026-08-17-collaborative-editor-roadmap.md`,
  item 1) for the follow-up.
- **No reverse tokenization**: editing a chip's displayed text does nothing —
  it is an atom node, not editable inline — and there is no write-back path
  from the document into the ERP record yet (master spec §3.2 "Write-Through"
  and §9 "Reverse Tokenization" are still open; same roadmap plan, item 5).
- **Tests**: `tests/Feature/Documents/LiveTokenChipTest.php`.

## Anchored comments (2026-08-17 addition, §8.3)

A comment can now pin to a specific block — paragraph, heading, list item,
or table row — instead of only ever being document-level.

- **Stable block ids**: `resources/js/editor/block-anchor.js`'s `BlockAnchor`
  extension stamps a `data-block-id` on every paragraph/heading/list-item/
  table-row the moment it exists (a ProseMirror `appendTransaction`, so it
  survives typing, splitting, pasting). The id itself is opaque — only
  uniqueness and stability across edits matter.
- **Pinning a comment**: the "Comment here" toolbar button reads the block id
  at the caret (`editor.storage.blockAnchor.currentBlockId()`) and calls
  `$wire.anchorNextCommentTo(id)`; the comment form shows a "Pinned to a
  specific paragraph" chip with a Clear button. `Edit::postComment()` passes
  `commentAnchorId` through to `DocumentComments::post(..., anchorId: …)`,
  which clears back to `null` after posting.
- **Storage**: `business_document_comments.anchor_id` (nullable string),
  migration `2026_09_18_000002_add_anchor_id_to_business_document_comments`.
  A reply inherits its parent's anchor rather than needing its own — a
  thread stays pinned to the block it started on.
- **Sanitizer**: `data-block-id` is now allowed on `p`, `h1`, `h2`, `h3`,
  `li`, `tr` — nothing else on those tags.
- **Deliberately NOT built**: live in-editor highlighting/badges on blocks
  that already have open threads (`DocumentComments::openThreadCountsByAnchor()`
  computes the data and is exposed to the view, but nothing decorates the
  ProseMirror DOM with it yet — a straightforward follow-up, not attempted
  here to keep this change reviewable). Audio annotations (§8.3's other
  half) are not built at all.
- **Tests**: `tests/Feature/Documents/DocumentCommentTest.php` (backend),
  `tests/Feature/Documents/RichEditorTest.php` (Livewire wiring).

## What shipped

| Piece | Where |
| --- | --- |
| Editor screen (Livewire) | `app/Livewire/Papers/Edit.php` + `resources/views/livewire/papers/edit.blade.php` |
| Tiptap island (Alpine) | `resources/js/editor/richtext.js`, registered as `opesRichEditor` in `resources/js/app.js` |
| Server-side sanitizer | `app/Services/Documents/HtmlSanitizer.php` |
| Soft edit-lock | `app/Services/Documents/EditLocks.php` + migration `2026_08_17_000300_add_editing_lock_to_business_documents.php` (`editing_user_id`, `editing_heartbeat_at`) |
| Autosave/version cadence | `DocumentVersioner::withAutosaveCadence()` / `snapshotThrottled()` / `checkpoint()` |
| HTML body rendering | `DocumentComposer::toHtml()` passes an HTML body through the sanitizer; plain template prose still converts as before, so show + print/PDF work for both |
| Policy | `BusinessDocumentPolicy::update()` now rides on `papers.create` (the group has no `update` slug; see the `mayWrite()` docblock) |
| Tests | `tests/Feature/Documents/RichEditorTest.php` (20 tests: sanitizer XSS, lock lifecycle, cadence, immutability, print) |
| Guide | `resources/guides/documents-workspace.md` → "Editing" |

Dependencies added (npm, no CDN): `@tiptap/core`, `@tiptap/starter-kit`,
`@tiptap/extension-table`, `@tiptap/pm`.

## The route line (not added — routes were out of scope for this change)

Add to the papers group in `routes/web.php` (next to `papers.edit`), plus the
import `App\Livewire\Papers\Edit as PapersEdit`:

```php
Route::get('/library/{paper}/editor', PapersEdit::class)->middleware('can:view,paper')->name('papers.editor');
```

`can:view` on purpose: the screen is useful read-only (comments, versions,
watching the writer), and the component enforces `update` before any write.
The "Open editor" button on Papers Show and the Compose→editor redirect are
both guarded by `Route::has('papers.editor')`, so they light up the moment
this line lands and are invisible until then.

## Design decisions worth knowing

- **Bodies**: a rich-edited body is stored as sanitized HTML in the existing
  `body` column. `toHtml()` distinguishes by shape (leading `<`); a document
  never mixes the two. Sanitization runs on save *and* on render — allowlist
  (toolbar vocabulary only), event handlers and `javascript:`/`data:` URLs
  stripped, links forced to `rel="noopener noreferrer"`.
- **Lock is advisory and self-healing**: 30 s `wire:poll` heartbeat, stale at
  90 s (three missed beats). A save re-*claims* rather than checks, so a lock
  that went stale unclaimed re-arms silently; only a live lock in other hands
  refuses a save. Lock writes go through the query builder (no model events),
  so lock churn never versions, never touches issued-immutability.
- **Version cadence**: autosaves mint at most one version per author per
  5 minutes (`AUTOSAVE_WINDOW_SECONDS`); `checkpoint()` on release/close
  captures the session's final state; a different author always versions
  immediately. Version rows stay immutable, so throttling happens at mint
  time — the full argument is in the `DocumentVersioner` docblocks.
- **Issued stays immutable**: the model guard in `BusinessDocument::booted()`
  is untouched; the editor additionally refuses to open issued documents.

## Realtime upgrade path (Reverb on the VPS)

Step 1 — presence without keystrokes (cheap, no data-model change):
1. Self-host Laravel Reverb on the VPS (`composer require laravel/reverb`,
   `php artisan reverb:install`; run `reverb:start` under supervisor, proxy
   `/app` websockets through nginx with TLS).
2. Presence channel per document (`presence-papers.{id}`, authorized by the
   `view` policy). Replace the `wire:poll` heartbeat with presence join/leave
   — the lock's staleness rule stays as the fallback for dropped sockets —
   and broadcast `DocumentSaved` so readers refresh on save instead of on
   poll.

Step 2 — true collaborative editing (keystroke realtime):
1. Tiptap is ProseMirror, so the native path is **Yjs**: add `yjs`,
   `@tiptap/extension-collaboration` (+ `collaboration-caret`), and a
   y-websocket-compatible server on the VPS (Node `y-websocket`, or Hocuspocus
   which speaks Yjs and can call Laravel webhooks for auth/persistence).
2. The Yjs document becomes the live source of truth while an editor session
   is open; persist debounced snapshots through the existing save path so
   `body`, the sanitizer, the versioner and issuance stay exactly as shipped.
3. The soft lock then retires to a per-*selection* awareness indicator — the
   claim/stale machinery can be deleted once every write goes through CRDT
   merge instead of last-writer-wins.

Do step 1 only when the VPS exists; nothing in v1 pretends to be realtime.
