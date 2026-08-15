# Documents module — existing-system audit

**Date:** 2026-08-15
**Status:** Awaiting validation. No code written, per §82 of the brief.

---

## 0. The headline

Three findings decide the shape of this module. Two are blockers.

**A. The name `documents` is already taken, by the ERP.**
`documents` holds 67 live rows and is the transactional table — invoices,
quotations, proformas, receipts, purchase orders, goods received notes, credit
notes. `App\Models\Document` is 236 lines of sales logic. §48 of the brief
proposes creating a `documents` table; doing so would collide with the single
most load-bearing table in the product.

**B. A Documents module already exists in embryo, and the brief does not know
about it.** `business_documents` + `App\Models\BusinessDocument` +
`DocumentComposer` + `DocumentTemplates` + the Papers screens already do
template-driven document composition, issuing, voiding, and — significantly —
**tamper-evident finalisation with QR verification**. This is the thing to
extend. Building alongside it would create the exact duplication §83 forbids.

**C. Roughly a third of the brief cannot be built on this codebase as it
stands**, because it depends on subsystems that do not exist (departments,
projects, a PDF engine, realtime, AI, OCR, private storage). Detail in §3.

---

## 1. Existing-system audit

| Area | State | Evidence |
|---|---|---|
| ERP transactional records | `documents` + `document_lines`, 10 `DocumentType` cases | `App\Models\Document` |
| **Composed documents** | **`business_documents`** — template, fields (json), body, status, issued_at, voided_at, content_hash, verification_token_id | `App\Models\BusinessDocument` |
| Template engine | `App\Support\DocumentTemplates` — hardcoded PHP array, typed fields (`key`, `label`, `type`, `required`, `default`) | service_agreement, nda, … |
| Composition | `App\Services\DocumentComposer` | Papers/Compose screen, live preview |
| **File storage** | **`media`** — polymorphic `attachable_type`/`attachable_id`, `disk`, `path`, `mime`, `size`, `checksum`, `width`, `height` | already the attachment abstraction |
| **Audit trail** | **`activity_log`** — polymorphic subject, `event`, `properties`, `ip`, `user_agent` | used by Admin controllers |
| **QR / verification** | `verification_tokens` + `/v/{token}` + `VerificationController` | loyalty, VIP, business documents |
| **Integrity** | `content_hash` + `canonicalPayload()` + `isTampered()` | on BusinessDocument today |
| Notifications | Laravel `notifications` + `NotifyCompany::about()` (permission-scoped fan-out) | 12 notification classes |
| Auth / roles | Sanctum, `roles`, `Permissions::CATALOGUE`, per-ability gates | |
| Tenancy | `BelongsToCompany` + `CurrentCompany` global scope, fails closed | |
| Numbering | `DocumentNumbers` service, per-company sequential series | |
| Branding | `companies.branding` → `BrandPalette`, logo, letterhead, watermark | built this session |
| Offline / PWA | `sync_receipts`, `sync_sequences`, `device_id`, queued replay | |
| Queue | `database` driver, working | |
| Employees | `employees` table exists (HR) | |
| **Departments** | **DOES NOT EXIST** | |
| **Projects** | **DOES NOT EXIST** | |
| **PDF engine** | **DOES NOT EXIST** — PDFs are `window.print()` on print-tuned Blade | no dompdf/snappy in composer.json |
| **DOCX** | **DOES NOT EXIST** — no reader, no writer | |
| **Search infra** | **DOES NOT EXIST** — no Scout, no FTS index | |
| **Realtime** | **DOES NOT EXIST** — `BROADCAST_CONNECTION=log`, no Reverb/Pusher | |
| **AI** | **DOES NOT EXIST** — no provider SDK | |
| **OCR** | **DOES NOT EXIST** | |
| **Private storage** | **DOES NOT EXIST** — only `Storage::disk('public')` | everything uploaded is world-readable |

---

## 2. Documents-related capabilities that already exist

Category A — reuse as-is, build nothing:

- Authentication, roles, per-ability gates
- Multi-tenancy and its global scope
- `media` for file storage, with checksums already
- `activity_log` for the audit trail (§30)
- `verification_tokens` + `/v/{token}` for QR verification (§29)
- Notifications and permission-scoped fan-out (§33)
- Company branding, logo, letterhead, watermark (§45)
- Contacts / customers / suppliers / employees (§9, §56)
- Document numbering service (§25)
- Offline sync machinery (§50)

Category B — exists, extend:

- `business_documents` → becomes the Documents record
- `DocumentTemplates` → move from hardcoded array to a table
- `DocumentComposer` → gains rich body + ERP field binding
- `content_hash` / `isTampered()` → extend to versions (§71)
- Papers screens → become the Documents workspace

Category C — genuinely new:

- Folders, tags, versions, comments, workflow, approvals, signatures,
  shares, retention, packages, checklists, relationships, search index,
  private storage, dashboard, action centre

---

## 3. Duplicate / conflict analysis

**Blocking conflicts**

| # | Conflict | Resolution |
|---|---|---|
| 1 | `documents` table is the ERP's | Namespace the DMS. Recommend `business_documents` (already exists) as the root, with `business_document_*` children. Never touch `documents`. |
| 2 | Brief's §48 table list would collide on `documents`, `document_types`, `document_comments`, `document_versions` — all read as ERP-adjacent | Rename every one to the `business_document_*` prefix |
| 3 | `App\Models\Document` vs a new `Document` | The DMS model stays `BusinessDocument` |

**Missing prerequisites — features that cannot be built as specified**

| Brief section | Depends on | Status |
|---|---|---|
| §10 department folders, §18 department approval, §75 by-department | `departments` | **Does not exist.** Either build an HR sub-module first, or cut. |
| §9, §36 project relationships, §4 project doc types | `projects` | **Does not exist.** Same choice. |
| §24 PDF export, §39 combined PDF, §72 watermark on PDF | server-side PDF engine | **Does not exist.** Today PDF = browser print. Adding one is its own project. |
| §23 DOCX import, §24 DOCX export | DOCX reader/writer | **Does not exist.** Large, and the weakest value-per-effort item in the brief. |
| §14 concurrent editing, presence, cursors | websockets | **Does not exist.** `BROADCAST_CONNECTION=log`. |
| §41, §42 AI assistant and AI search | an AI provider | **Does not exist.** |
| §27 OCR | OCR engine | **Does not exist.** |
| §26 content search | FTS or Scout | **Does not exist.** MySQL FULLTEXT is the cheap route. |
| §21 secure external links, §61 private URLs | a private disk | **Does not exist — and this is a live security gap today.** Everything in `media` is on the public disk. |

I want to be direct about one of these: **§16 track changes and §14 concurrent
editing are the two most expensive items in the brief and the two least likely
to be worth it here.** Google Docs-grade collaborative editing is an operational
transform / CRDT problem with a websocket tier behind it. On an offline-first
PWA aimed at Cameroonian SMEs on budget Android, it would be months of work for
a feature most tenants will never have two people using simultaneously. My
recommendation is explicit locking (§70) instead — which the brief already asks
for, and which actually fits the connectivity reality.

---

## 4. Reusable services

`Media` · `ActivityLog` · `VerificationToken` + `QrCodes` · `NotifyCompany` ·
`Permissions` + gates · `BelongsToCompany` / `CurrentCompany` ·
`DocumentNumbers` · `BrandPalette` + letterhead + watermark ·
`DocumentComposer` · `DocumentTemplates` · queue · sync machinery

---

## 5. Required new components (core, phase 1–4 only)

Models: `BusinessDocumentVersion`, `BusinessDocumentFolder`,
`BusinessDocumentTemplate`, `BusinessDocumentComment`,
`BusinessDocumentShare`, `BusinessDocumentRelation`,
`BusinessDocumentApproval`, `BusinessDocumentSignature`

Services: `DocumentVersioning`, `DocumentFields` (the extensible registry),
`DocumentWorkflow`, `DocumentSharing`, `DocumentSearch`, `DocumentRetention`

---

## 6. Required schema changes

Extend `business_documents`: `type`, `folder_id`, `owner_id`, `security_level`,
`language`, `current_version`, `locked_at`, `locked_by`, `expires_at`,
`retention_until`, `legal_hold`, `number`, `description`.

New tables, all `business_document_` prefixed. `documents`, `document_lines`,
`media` and `activity_log` are **not** altered.

---

## 7–8. Integration and API map

Documents reference ERP records through one polymorphic
`business_document_relations` table (`related_type`, `related_id`, `role`).
Every ERP entity page gains a Documents tab reading through it. Context is
carried into creation so a document opened from a customer arrives with that
customer attached (§67).

API extends the existing `/api/v1` conventions, ability scopes and idempotency
middleware — no new auth, no new envelope.

---

## 9. Permission model

Extends `Permissions::CATALOGUE` with a `Documents` group:
`view, create, update, delete, share, approve, sign, manage`.

Per-record grants live in a `business_document_permissions` table, evaluated
server-side in a policy. Confidentiality levels are a column, enforced in the
policy — never in the view.

---

## 10–11. Workflow and UI

Configurable stages, defaulting to
`draft → in_review → approved → pending_signature → signed → published → archived`.

UI reuses the existing design system entirely: `x-ui.panel`, the pill filters,
`card`, the token palette. The Papers screens grow into the workspace.

---

## 12. Migration plan

Additive only. No rename, no drop, no data deletion. Existing
`business_documents` rows get sane defaults and keep working — the Papers
screens must pass their current tests untouched at every step.

---

## 13. Testing plan

Every phase ships with tests, and the **full 1500-test suite runs before each
commit** — §78 regression protection is not a final gate, it is the per-commit
gate. Specific risks to pin: the ERP `documents` table is untouched; Papers
screens still work; `media` and `activity_log` behaviour unchanged; tenancy
isolation on every new table; policy denies before the UI hides.

---

## Proposed decomposition

The brief is ~15 modules. It cannot be one spec. Ordered by value:

1. **Core** — extend `business_documents`, folders, types, metadata, upload via `media`, dashboard, ERP relations, context-aware creation
2. **Versions and locking** — history, restore, compare, finalisation, hash per version, explicit locking
3. **Collaboration-lite** — comments, mentions, notifications, action centre
4. **Workflow, approvals, signatures**
5. **Sharing and security** — *includes creating the private disk; this fixes a live gap*
6. **Templates as data** — migrate `DocumentTemplates` into a table, ERP field registry
7. **Search** — MySQL FULLTEXT
8. **Retention, packages, bundles, checklists, analytics**

Deferred pending prerequisites: departments, projects, PDF engine, DOCX,
realtime co-editing, AI, OCR.

---

## What I need decided

1. **Namespace** — confirm `business_documents` as the root rather than a new `documents` table.
2. **Departments and projects** — build them first, or cut every feature that depends on them?
3. **Collaborative editing** — accept locking instead of realtime co-editing?
4. **PDF/DOCX export** — is a server-side PDF engine in scope, or does print-to-PDF remain the answer?
5. **Start point** — confirm phase 1 as the first spec.
