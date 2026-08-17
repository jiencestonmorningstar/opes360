# Rich document editor v1 — handoff

The "many people work on a document" capability, honestly scoped: rich
editing + autosave + version snapshots + soft edit-locking + the existing
comments. **Not** keystroke-realtime — that upgrade path is at the bottom.

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
