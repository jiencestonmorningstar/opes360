# Collaborative Editor Roadmap — gap plan against the master spec

> Source: `opes360_master_document1.md` v3.0 (2026-08-15), the architecture
> vision for a Google-Docs-class engine fused into the ERP. This plan checks
> that vision against what actually exists in the working tree on 2026-08-17
> and lists only the gaps — not a re-description of what's already built.

**Method.** Every "not built" line below was verified directly, not inferred:
`resources/js/editor/richtext.js` has zero matches for chip/token/autocomplete/
mention machinery (plain Tiptap only); `App\Services\Documents\DocumentFieldRegistry`
resolves `{{table.field}}` once at compose time server-side, with no WebSocket
push and no reverse-write path; `business_documents` has `editing_user_id`/
`editing_heartbeat_at` (soft lock) and nothing resembling paragraph-level node
locking or CRDT/OT.

---

## What's already real (do not rebuild)

| Master spec § | What it describes | What actually exists |
|---|---|---|
| §3.2 token syntax | `{{table.field}}` | `DocumentFieldRegistry`, resolved once at compose time by `RecordDocumentComposer` |
| §3.3 rendered snapshots | Frozen legal text at milestone states | `BusinessDocument::canonicalPayload()`, hash-verified via `VerificationToken` |
| §4.2 milestone versioning | Explicit version rows, not per-keystroke | `DocumentVersioner`, `business_document_versions` |
| §5.1 (partial) concurrency | *Some* lock during co-edit | Soft/whole-document lock only (`editing_user_id`, 90s heartbeat, "request takeover") — not paragraph-level |
| §7 lifecycle state machine | Draft → Review → Approved → Archived | `BusinessDocument` status + `DocumentRetention` lifecycle label |
| §6 visual masking of restricted fields | `{{employee.salary}}` → •••• | **Verified moot, 2026-08-17**: every provider registered in `AppServiceProvider` (`company`, `customer`, `employee`, `project`) exposes only name/address-class fields — no salary, SSN, or other sensitive field is registered anywhere. There is currently nothing for a mask to hide. Building masking now would be speculative infrastructure for a field that doesn't exist; the real trigger is the day a module registers a genuinely sensitive field, at which point that module's registration is the place to add the permission check — not a platform-wide masking layer built ahead of need. |

## Confirmed gaps, mapped to the master doc's own §9.1 phases

### Phase 2 (short term) — editor-side tokens and state-machine polish

1. ~~**Smart chips + `@` autocomplete in the Tiptap editor**~~ (§3.1, §3.2) —
   **shipped 2026-08-17**. `resources/js/editor/field-token.js` adds an
   inline atom chip node plus an `@`-triggered picker;
   `DocumentComposer::resolveFieldChips()`/`toHtml($body, $document)`
   re-resolve each chip's value against the document's linked ERP record on
   every render, so a chip shows the *current* value, not what was true when
   it was typed. See `docs/handoff/rich-editor.md` for the full design.
   **Not** built as part of this: `/`-slash command insertion (only `@` was
   scoped — a slash command for the same picker would be a small follow-up,
   not a new subsystem).

1a. ~~**Freeze field chips to plain text at issuance**~~ (§3.3 "Rendered Text
    Engine") — **shipped 2026-08-17**. `DocumentComposer::freezeFieldChips()`
    runs inside `issue()`, right before the content hash is computed: each
    `<span data-token>` is resolved one last time and unwrapped to bare
    resolved text, so the hash covers what a reader actually sees, and a
    later rename of the linked customer/employee/project can never change
    what an already-issued document reads. A body with no chips is left
    byte-for-byte untouched. See `tests/Feature/Documents/LiveTokenChipTest.php`.
2. ~~**Visual masking of restricted fields**~~ (§6) — **verified moot,
   2026-08-17**, not built. See the table above: no sensitive field is
   registered yet, so there is nothing to mask. Revisit when a module
   actually registers one.
3. **Paragraph/line-level locking** (§9 roadmap item, extends §5.1). Current
   lock is whole-document. Real gap only if multiple simultaneous editors are
   a product priority — confirm before investing, since it requires a
   presence channel (see Phase 3 below) to know where cursors are at all.

### Phase 3 (medium term) — real-time infrastructure

4. **WebSocket read-through** (§3.2). No Reverb/Pusher broadcasting exists for
   document field changes. This is a prerequisite for chips to "update
   instantly" rather than only reflecting the value at compose time — and a
   prerequisite for any locking finer than whole-document.
5. **Reverse tokenization / write-through** (§3.2, §9 "Reverse Tokenization").
   No mechanism lets an edit inside a token chip write back to the ERP row,
   gated by authorization. Genuinely new: needs a diff/approval flow, not
   just a form post.
6. **Ephemeral collaboration cache + CRDT/OT merge** (§4.1, §5.1, §5.2). No
   in-memory operation log, no CRDT/OT library integrated. This is the actual
   "Google Docs" part and is the largest single item in the whole document —
   do not schedule casually; it changes the storage and transport model of
   the editor.
7. **ERP activity stream integration for document edits** (§9). Distinct from
   #4 — this is document→dashboard notification, not document→document sync.
   Cheaper: could ride the existing `document.*` domain event stream
   (`App\Support\DomainEvents`) that already exists for other Documents
   actions; check whether editor saves already emit into it before building
   new plumbing.

### Phase 4 (long term) — the four elite modules (§8)

None of these exist. Each is a substantial subsystem in its own right and
should get its own plan (and likely its own spec/brainstorm pass) rather than
a subtask here:

8. **§8.1 Collaborative CLM** — contract state transitions triggering
   AR/AP holds, inventory allocation, milestone billing. Partially
   buildable today on top of the existing lifecycle state machine (#3 above)
   and domain events, without needing #6 first.
9. **§8.2 Collaborative BI spreadsheet** (`OPES_SUM`, `OPES_LOOKUP`
   formulas). Entirely new: a formula-cell grid, not the rich-text editor.
   Independent of the rest of this roadmap.
10. **§8.3 Contextual communication hub with audio annotations**. Comments
    anchored to specific blocks already partially exist (`DocumentComments`
    — see gap analysis §15) but block/sentence-level anchoring and audio
    notes do not.
11. **§8.4 RFP/vendor portal**. New external-facing surface, analogous to
    the existing `DocumentSharing` external-link pattern but with structured
    input fields rather than view-only access.

---

## Recommended sequencing

Build in the order listed under Phase 2 first (#1–#3) — they're additive to
what already exists, require no new infrastructure, and make the current
one-shot token system visibly useful. Do not start #6 (CRDT/OT) without a
dedicated spec: it's the one item in this document that changes the editor's
fundamental storage/transport model, and building it half-way (e.g. adding a
websocket without the operational-transform merge behind it) would produce
something that looks real-time but silently drops concurrent edits.

Items #8–#11 (the four elite modules) are independent of each other and of
the real-time work — any one of them could be picked up in parallel without
waiting on #4–#7.
