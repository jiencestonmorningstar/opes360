# Gap analysis — what is built, part-built, and not built

**Date:** 2026-08-16
**Measured against:** `docs/superpowers/specs/2026-08-16-documents-master-spec.md`
(the Documents master specification and the ERP completeness checklist inside
it).

**Method.** Every line below was checked against the working tree on
2026-08-16 — models, migrations, services, Livewire components, routes,
`config/modules.php`, `app/Support/Permissions.php` and `docs/API.md`. Nothing
here is inferred from the brief or from what a module's name suggests.

**Legend.** **A** already exists, reuse it · **B** partly exists, extend it ·
**C** does not exist.

---

## Part 1 — The Documents module

Phase 1 of `docs/superpowers/plans/2026-08-15-documents-core.md` is roughly
half delivered. The table follows the master spec's own section numbers.

### Built (A)

| § | Capability | Where |
|---|---|---|
| 4 | Document type catalogue, 27 kinds in 6 groups, no ERP transaction types | `app/Support/DocumentKinds.php` |
| 9 | ERP relationships — polymorphic, many-to-many, no ERP row copied | `business_document_relations`, `DocumentLinker` |
| 10 | Folders — nested, cycle-refused, depth-capped, deleting a folder keeps its documents | `BusinessDocumentFolder` |
| 11 | Tagging (free vocabulary) | `business_documents.tags` |
| 20 | Confidentiality levels — public/internal/confidential/restricted | `DocumentKinds::securityLevels()` |
| 23 | Upload as a managed document, original preserved, checksum stored | `DocumentFiler` |
| 28 | Metadata — kind, description, security, language, tags, owner, expiry, checksum, MIME, size, storage ref | `2026_08_24_000001_extend_business_documents` |
| 29 | Integrity — content hash, tamper detection, QR verification | `BusinessDocument::isTampered()`, `VerificationToken` |
| 49 | Private storage disk, no public URL | `documents` disk |
| 61 | MIME/extension allow-list, size limit, upload validation | `DocumentFiler` |
| 66 | Documents panel on the customer record | `components/documents/library-panel.blade.php` |
| 67 | Context carried from the ERP record it was opened from | same |
| 69 | Duplicate detection by checksum — warns, never deletes | `DocumentFiler` |
| 70 | Issued documents immutable; filing columns allow-listed and outside the hash. **Locking added 2026-08-16** — a draft can be frozen against edits without being issued | `BusinessDocument::booted()` |
| 12 | Versions — one snapshot per content change, filing never versions, restore adds a version rather than rewriting one | `DocumentVersioner`, `business_document_versions` |
| 71 | Finalisation — not rebuilt; issuance already does this (freeze, hash, record who/when) | `BusinessDocument::booted()`, `canonicalPayload()` |
| 3 | Workspace — search, kind/security/folder/tag filters, five overview counters | `Livewire\Papers\Index` |
| 47 | Library API — list/show/create/file/delete, attach/detach relations | `LibraryController` |

Reused rather than rebuilt, exactly as the brief requires: the `media` table and
upload path, `users`/`roles`/`permissions`, `contacts`, the `documents` ERP
table, `activity_log`, the QR verification infrastructure, the notification
layer, the company/branding configuration, and the offline sync engine.

### In progress right now (B)

| § | Capability | State |
|---|---|---|
| 20 | Permission model — `papers.share`, `papers.manage`, restricted enforced in the policy | **Done.** |
| 3 | Workspace dashboard — counters, search, filters, folder tree | **Done.** Confidentiality enforced at the query via `scopeReadableBy()`, not filtered after fetching |
| 47 | API — `/api/v1/library` and `/relations` | **Done.** `docs/API.md` §22. A `file` ability, separate from `update`, so an issued document can still be filed — `update` correctly requires a draft |

### Not built (C)

Grouped by why, because "not built" covers three very different situations.

**Deferred for a missing prerequisite — the dependency does not exist in the
codebase at all:**

| § | Capability | Missing prerequisite |
|---|---|---|
| 6 | Rich document editor | No editor; composition today is template-field fill |
| 22 | Preview (PDF, DOCX, office formats) | No preview/conversion tier |
| 23–24 | DOCX import/export | No DOCX engine |
| 24 | Server-side PDF export | PDF is `window.print()` today |
| 14, 16 | Concurrent editing, presence, track changes | No websocket tier |
| 26 | Content and indexed search | No search index |
| 27 | OCR | No OCR service |
| 41–42 | AI assistant and AI search | No AI provider configured |
| 43 | Translation workflows | No translation service |
| 9, 10 | Department, project and dossier folders | **Neither departments nor projects exist as entities.** `employees.department` is a free-text string, not a table |

**Not yet started, no prerequisite missing — these are simply the rest of the
roadmap:**

| § | Capability |
|---|---|
| 5 | Creation routes beyond blank/template/upload — duplicate, from existing, from ERP record, from workflow, from automation |
| 7 | Dynamic ERP field registry — modules registering their own authorised fields |
| 8 | Templates as data (create/edit/version/publish/permission). Templates are a hard-coded PHP catalogue today |
| 13 | Version comparison — versions themselves now exist (see the Built table) |
| 15 | Comments, replies, mentions, resolution |
| 17–18 | Workflow stages and the approval engine |
| 19 | Signature requests, fields, ordering, status |
| 21 | External sharing — secure links, expiry, password, revocation, access log |
| 25 | Configurable document numbering per type |
| 30 | Documents-specific audit trail (the suite has `activity_log`; Documents does not write a full document audit yet) |
| 31–32 | Retention policies, legal hold, controlled disposal, full lifecycle states |
| 33–35 | Alerts, the My Actions centre, the per-document activity timeline |
| 36–40 | Related-content panel, dossiers, packages, bundles, checklists |
| 44 | Multilingual templates (the `language` column exists; nothing consumes it) |
| 46 | Documents administration screens |
| 50–51 | Offline document handling and the mobile document interface |
| 53 | The full document view with its side panels |
| 54, 59 | Automation hooks and the `document.*` event stream |
| 58 | Extension points for other modules to register types, fields, triggers |
| 60 | Background/queued processing for large files |
| 68 | Bulk operations |
| 71–73 | Finalisation artefacts, watermarks, print control by permission |
| 74–75 | Analytics and the admin dashboard |

**Argued against rather than merely deferred** (recorded so the decision is
visible, not silent):

- **§14 realtime co-editing.** Months of OT/CRDT work plus a websocket tier,
  for something most tenants will never have two people doing simultaneously,
  on an offline-first PWA. Locking (§70) buys most of the value for a fraction
  of the cost.
- **§23–24 DOCX import/export.** The weakest value-per-effort item in the
  brief.

---

## Part 2 — The ERP completeness checklist

### Tier 1

| # | Domain | Verdict | What is actually there / missing |
|---|---|---|---|
| 1 | Accounting & GL | **B** | Chart of accounts, journal entries, GL, trial balance, income statement, balance sheet, tax declarations, multi-currency, multi-company all exist (`Services/Accounting/Books.php`). **Missing: cash-flow statement, fiscal periods, period closing, cost centres.** |
| 2 | Banking & treasury | **B** | Bank and cash accounts, statement import, reconciliation, payment matching. **Missing: transfers between accounts, cash forecasting.** |
| 3 | Accounts receivable | **B** | Balances, 30-day aging with drill-down and CSV, allocation, credit notes, dunning at 7/30/60 days. **Missing: debit notes, customer statements, a collections workspace.** |
| 4 | Accounts payable | **B** | Supplier bills via expenses, AP aging, allocation. **Missing: payment scheduling, supplier statements, supplier reconciliation.** |
| 5 | Procurement | **B** | Purchase order → goods receipt → three-way match. **Missing: requisitions, RFQ, supplier quotations, approval workflows.** |
| 6 | Inventory / warehouse | **B** | Multi-location, movements, transfers, adjustments, stocktakes, reorder levels, valuation. **Missing: batch/lot tracking, serial numbers, expiry dates, stock reservations.** |
| 7 | Sales & CRM | **B** | Full sales paperwork, deal pipeline with stages and closure rules, customer history, recurring invoices. **Missing: leads as a distinct entity, activity logging against a deal, sales forecasting.** |
| 8 | Expenses | **B** | Expenses, categories, payments, void. **Missing: employee expense claims, reimbursement, cost-centre allocation.** |
| 9 | HR | **B** | Employees, employment contracts, leave requests. **Departments became an entity 2026-08-16** (nested, managed, archivable; the old free-text column is backfilled and kept). **Missing: positions as an entity, attendance, performance, recruitment.** |
| 10 | Payroll | **A** | Runs, salary components, allowances, deductions, payslips, approval, posting to the books. |
| 11 | Projects | **B** | **Built 2026-08-16**: projects, milestones, tasks, time entries with locking, cost-to-date (labour at its logged rate plus expenses), billable/internal distinction, `/projects` workspace. Expenses and the ERP `documents` table both link to a project. **Missing: timesheet approval as its own screen (the workflow engine can be wired to it but isn't yet), resource allocation across projects, and the Documents §9/§10 project folders/dossiers this was meant to unblock** — those still wait on Documents phase 2. |
| 12 | Fixed assets | **B** | Register, acquisition, depreciation, disposal. **Missing: transfers, maintenance, asset locations.** |

### Tier 2

| # | Domain | Verdict | Note |
|---|---|---|---|
| 13 | Manufacturing | **C** | Nothing. Only relevant if OPES360 targets manufacturers. |
| 14 | Supply chain | **C** | Nothing beyond reorder levels. |
| 15 | Logistics / fleet | **C** | Nothing. |
| 16 | Service management | **C** | Nothing. (`Ticket` is event ticketing, not a service desk — an easy misreading of the model list.) |
| 17 | Contracts management | **C** | Nothing. Should be built **on** Documents, per the brief. |
| 18 | Compliance & risk | **C** | Nothing. |
| 19 | BI & analytics | **B** | Reports and aging exist; no executive dashboard, KPI set, profitability or forecasting. |
| 20 | Workflow & approval engine | **A** | **Built 2026-08-16.** Workflows, ordered steps, instances, assignments and immutable decisions. Sequential, parallel and numeric quorum; role/department/user/owner/manager/creator approver modes resolved at assignment time; amount-based and field conditions as data; delegation with provenance; reject vs. changes-requested kept distinct; a step with no possible approver stalls rather than passing. `/actions` is the cross-module inbox. See `docs/workflows.md`. **Still missing: trigger→action automation** (the condition and action halves exist; nothing yet fires a workflow off an event) **and the workflow admin screen** — workflows are defined in data today. `document_approvals` remains as the sales-specific mechanism, untouched on purpose. |
| 21 | Notifications | **B** | 20 notification classes, mail and in-app. **Missing: SMS, WhatsApp, user-configurable rules, escalations.** |
| 22 | Enterprise Documents | **B** | Part 1 above. |
| 23 | E-signature | **C** | Nothing. Belongs inside Documents. |
| 24 | Audit & governance | **B** | `activity_log`, webhook delivery log, platform-admin activity, device register. **Missing: login audit, permission-change audit, export audit, a governance view over any of it.** |
| 25 | Administration | **B** | Companies, users, roles, permissions, numbering, currencies, taxes, module switches, branding. **Missing: branches, departments, fiscal periods, approval rules, workflow configuration.** |

### The brief's three concerns, answered

1. **"A proper accounting engine."** Largely present, and stronger than the
   brief assumed — double-entry, GL, trial balance, income statement, balance
   sheet, AR/AP aging, bank reconciliation and tax declarations are all built.
   The real remaining holes are **fiscal periods, period closing, cost centres
   and a cash-flow statement**, not the engine itself.
2. **"One workflow engine."** Correct, and unbuilt. This should be built
   **before** Documents §17–18, or Documents will ship the fourth
   approval mechanism in the product.
3. **"The Documents platform."** Half-built; Part 1 says exactly which half.

---

## What this changes about the plan

Three items, in order of consequence:

1. **The workflow engine now outranks Documents §17–19.** The brief's own rule
   forbids a Documents-only approval engine, so either the platform engine
   comes first or those sections stay deferred. Deferring them is the cheaper
   answer for now; building a Documents-local one is the answer that will have
   to be undone.
2. **Projects (Tier 1 #11) blocks several Documents sections** — project
   folders, project relationships, project dossiers, project field binding. It
   was cut from the Documents plan as "not a Documents job", which remains
   right; it is a Tier-1 ERP gap in its own right.
3. **Departments are needed in two places at once** — Documents §3/§9/§10 and
   ERP #9/#25 — and exist as a free-text string on `employees`. Promoting them
   to an entity is a small, self-contained piece of work that unblocks a
   disproportionate amount of the brief.

The rest of the brief's architectural rules are already being followed:
Documents extends `business_documents` rather than claiming `documents`,
references ERP rows instead of copying them, reuses the media/upload path, the
auth system, the QR verification infrastructure, the notification layer and the
existing design system, and creates no competing transactional type.
