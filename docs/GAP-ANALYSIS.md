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
| 12–13 | Versions and comparison — one snapshot per content change, filing never versions, restore adds a version rather than rewriting one, word-level diff between any two versions | `DocumentVersioner`, `VersionComparator`, `business_document_versions` |
| 71 | Finalisation — not rebuilt; issuance already does this (freeze, hash, record who/when) | `BusinessDocument::booted()`, `canonicalPayload()` |
| 3 | Workspace — search, kind/security/folder/tag filters, five overview counters | `Livewire\Papers\Index` |
| 47 | Library API — list/show/create/file/delete, attach/detach relations, versions, comments | `LibraryController` |
| 15 | Comments — replies, explicit-id mentions with notification, resolve/reopen, deletion by permission | `BusinessDocumentComment`, `DocumentComments` |
| 30, 35 | Audit trail and activity timeline — no new table; merges the existing audit log, versions and comments into one ordered feed | `DocumentActivity` |
| 21 | External sharing — expiring, password-protected, revocable links with an access log, view-only as a UX courtesy rather than an enforced restriction | `DocumentSharing`, `/share/{token}` |
| 36–38, 40 | Dossiers, packages, ZIP bundles, checklists — a dossier is a query over existing relations rather than a stored row, and a checklist's completeness is computed from what is actually linked rather than a tick that could drift. Packages hold references: removing from one, or deleting one, never touches a document | `DocumentDossiers`, `DocumentBundles` |
| 7 | Dynamic ERP field registry — `company.*`/`today` moved out of hard-coded `DocumentComposer` code into the registry's default provider (identical output, verified by the regression gate); `customer`/`employee`/`project` providers added as real working examples, contributing nothing gracefully when no record is in context. Any module registers its own the same way, in a `boot()`, without Documents knowing it exists. Caught and fixed a real bug while building this: the Papers gallery has offered a business's own published templates since the previous commit, but the Compose screen resolved templates through the built-in catalogue only, so opening one 404'd | `DocumentFieldRegistry` |
| 8 | Templates as data — a business can create, edit, version and publish its own templates, merged into the gallery alongside the built-in PHP catalogue rather than replacing it. `DocumentComposer` checks a business's published templates before falling through to the built-in ones, so every existing built-in template keeps composing exactly as before | `CustomDocumentTemplates`, `business_document_templates` |
| 25 | Configurable numbering per kind — reuses the existing offline-safe lease ledger (`DocumentNumbers`/`NumberLease`) rather than building a second numbering mechanism; a company that never configures anything keeps today's shared `DOC-2026-000001` series unchanged, kind by kind | `BusinessDocumentNumberingScheme`, `DocumentNumbers::nextForBusinessDocumentKind()` |
| 31–32 | Retention, legal hold, controlled disposal — retention periods are business-set data per document kind, never hard-coded; a legal hold overrides any schedule and blocks disposal outright; disposal is permanent, permission-gated, and refuses without an override parameter. Lifecycle exposed as a computed label layered on the existing draft/issued/void status rather than a second stored state machine that could disagree with it | `DocumentRetention`, `business_document_retention_policies` |

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
| 17–18 | Workflow stages and the approval engine |
| 30 | Documents-specific audit trail (the suite has `activity_log`; Documents does not write a full document audit yet) |
| 33 | Alerts — expiry and workflow-stalled notifications specifically for documents (the My Actions centre and its `workflow.stalled` event already exist; a scheduled expiry check does not) |
| 39 | **Combined-PDF bundles only.** ZIP bundles are built; merging a package into one PDF needs the server-side PDF engine that is still an open infrastructure decision (Phase 5) |
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
| 1 | Accounting & GL | **A** | Chart of accounts, journal entries, GL, trial balance, income statement, balance sheet, tax declarations, multi-currency, multi-company (`Services/Accounting/Books.php`). **Completed 2026-08-16:** fiscal years and periods, period closing enforced at `Ledger::post()` (the single posting path, so the rule cannot be bypassed), reopening as a deliberate reversible act, cost centres on journal lines, and a direct-method cash-flow statement that classifies by journal and reports "other operations" as unclassified rather than guessing. All opt-in: a business that defines no periods is unrestricted. |
| 2 | Banking & treasury | **A** | Bank and cash accounts, statement import, reconciliation, payment matching. **Completed 2026-08-16:** transfers between own accounts (posting through `Ledger::post()`, and deliberately not through `recordQuietly()` — a transfer *is* the bookkeeping, so a swallowed failure would be money that left one account and arrived nowhere), and an 8-week cash forecast built from committed receivables and payables rather than extrapolated from a trend. |
| 3 | Accounts receivable | **A** | Balances, 30-day aging with drill-down and CSV, allocation, credit notes, dunning at 7/30/60 days. **Completed 2026-08-16:** debit notes, customer statements with running balance and aging, and a prioritised collections queue with activity logging. `Document::scopeReceivables()` now defines a receivable once for aging, dunning, collections and statements — debit notes were invisible to the first two until it existed. |
| 4 | Accounts payable | **A** | Supplier bills via expenses, AP aging, allocation. **Completed 2026-08-16:** payment scheduling into a run with draft → approved → executed states (execution refuses an unapproved run, and `payables.approve` is seeded apart from `payables.manage` so the refusal is a real control rather than a speed bump); supplier statements as the genuine mirror of the customer one; and supplier reconciliation that never writes to `expenses` and never recomputes a supplier's own closing balance from their own lines — the disagreement *is* the product. The schedule takes only the receipts side of the cash forecast, because the payments side is the very thing being decided. |
| 5 | Procurement | **A** | Purchase order → goods receipt → three-way match. **Completed 2026-08-16:** requisitions, RFQs, supplier quotations, comparison and award. The approval is the existing workflow engine — the module has no `approve()` method and no threshold code; "over 10,000,000 needs the director" is a step condition. Awarding raises a *draft, unnumbered* purchase order, so a change of mind does not burn a number, and an uninvited supplier cannot quote. |
| 6 | Inventory / warehouse | **A** | Multi-location, movements, transfers, adjustments, stocktakes, reorder levels, valuation. **Completed 2026-08-16:** batch/lot tracking, serial numbers (modelled as a batch of one, so FEFO and recall have one path rather than two), expiry with FEFO picking, and reservations that reduce available without moving on-hand. Opt-in per product via `items.tracking_mode`, so a business that does not need it sees no change. |
| 7 | Sales & CRM | **A** | Full sales paperwork, deal pipeline, customer history, recurring invoices. **Completed 2026-08-16 — the last Tier-1 gap:** leads as a distinct entity whose conversion creates an ordinary Contact/Deal and whose loss keeps its reason; planned activities (calls, meetings, tasks) with "deals nobody has touched in N days" as the headline query; and a weighted sales forecast read straight off the pipeline — stage probabilities with per-deal override, months bucketed in PHP off the cast date to dodge the midnight trap, overdue deals rolled to the current month rather than flattering a past one. |
| 8 | Expenses | **A** | Expenses, categories, payments, void. **Completed 2026-08-16:** staff expense claims with per-line cost-centre allocation and instalment reimbursement, approved through the existing workflow engine rather than a second approval path. Staff debt posts to 422, not payables — employees in 401 would appear in the supplier ageing report and be chased like suppliers. |
| 9 | HR | **B** | Employees, employment contracts, leave requests. **Departments became an entity 2026-08-16** (nested, managed, archivable; the old free-text column is backfilled and kept). **Completed 2026-08-16:** positions as an entity (backfilled from `job_title`, which is kept because payroll snapshots it onto payslips), attendance at one row per person per day enforced by the database rather than by the writing code, and performance reviews that freeze on acknowledgement — an acknowledgement of a document that can still be edited is worth nothing in the dispute it is kept for. Attendance is deliberately *not* wired to payroll: correcting a timesheet must never silently rewrite a payslip already paid and declared. **Recruitment built 2026-08-16:** vacancies against existing positions with public token adverts (the forms share-token pattern), applications with kept stage history, interviews with per-interviewer scorecards, and offers whose letters are ordinary BusinessDocuments and whose approval is the shared engine — accepting re-checks the engine, not the cached column, so nothing hand-edited can hire anybody. Hiring creates a real Employee on the Team screen's own path. Rejected candidates are purgeable; the retention question is raised, not solved. |
| 10 | Payroll | **A** | Runs, salary components, allowances, deductions, payslips, approval, posting to the books. |
| 11 | Projects | **B** | **Built 2026-08-16**: projects, milestones, tasks, time entries with locking, cost-to-date (labour at its logged rate plus expenses), billable/internal distinction, `/projects` workspace. Expenses and the ERP `documents` table both link to a project. **Missing: timesheet approval as its own screen (the workflow engine can be wired to it but isn't yet), resource allocation across projects, and the Documents §9/§10 project folders/dossiers this was meant to unblock** — those still wait on Documents phase 2. |
| 12 | Fixed assets | **A** | Register, acquisition, depreciation, disposal. **Completed 2026-08-16:** asset locations as an entity (backfilled from the free-text `location` column, which is kept — `FixedAsset::locationRecord()` is named around Eloquent's attribute-shadows-relation rule, as `Employee::departmentRecord()` was), custodians, an immutable transfer history written in the same transaction as the asset's new position, and maintenance that can repeat — with the next occurrence counted from the day the work was *done*, not the day it was due. Servicing holds no authoritative cost of its own: it points at the existing `Expense`, so the month's spend cannot depend on which screen you ask. |

### Tier 2

| # | Domain | Verdict | Note |
|---|---|---|---|
| 13 | Manufacturing | **C** | Nothing. Only relevant if OPES360 targets manufacturers. |
| 14 | Supply chain | **C** | Nothing beyond reorder levels. |
| 15 | Logistics / fleet | **A** | **Built 2026-08-16, as an extension of fixed assets rather than a module of its own** — nine of the twelve things a fleet feature is usually asked for already existed on assets (register, location, driver, reassignment with history, service schedule, completion, cost-via-expense, disposal). What was genuinely missing: vehicle papers as a one-to-one extension of the asset, trips, fuel logs, expiry alerts, and servicing scheduled by **distance**. That last is the real addition, and it extends `AssetMaintenance` and `AssetServicing` rather than paralleling them, so there is still one outstanding list and one overdue count. The odometer is derived from the highest recorded reading, never stored — a stored counter goes stale the first time a mistyped trip is corrected, and the schedule would then count from a number nobody could reproduce. A fuel log holds no amount at all: it reads the expense every time. |
| 16 | Service management | **A** (+ walk-in triage 2026-08-16: the company's existing printed QR opens a public mobile intake at /triage/{token}; tickets are created only through TicketDesk with the SLA running, a visitor's "very urgent" caps at high because urgent is the business's promise to make, and the page never mints Contacts — an open public page must not fill the customer book with junk) | **Built 2026-08-16.** Tickets, visits, parts, and SLA policies with a working-hours calendar. Time is logged to the **existing** project timesheet (`service_job_id` added, `project_id` made nullable) rather than a second one; a maintenance visit completes the existing `AssetMaintenance`; billing drafts an ordinary invoice `Document` and holds a link, never a copy. SLA deadlines are stored as absolute instants already walked through the calendar, and are always *recomputed* as `opened_at + target + paused` rather than nudged, so pause/escalate/reopen are idempotent instead of drifting. The clock stops while waiting on the customer and while resolved. (`Ticket` remains event ticketing — an easy misreading of the model list.) |
| 17 | Contracts management | **A** | **Built 2026-08-16, on Documents as the brief specified** — the signed paper is a `BusinessDocument`, so versioning, retention, legal hold, sharing and e-signature already apply; contracts carry no number of their own. The centre of the design is `notice_by`, the last day notice can be served: stored rather than derived so it can be indexed, rewritten on every save so it cannot drift from `ends_on`, and refused outright when a contract auto-renews with an end date but no notice period, because that combination promises a warning that can never be given. `ContractWatch` keeps *deadline coming*, *deadline gone*, and *deadline gone on a self-renewing contract* as three separate lists rather than one that gets skimmed. |
| 18 | Compliance & risk | **A** | **Built 2026-08-16.** Statutory obligations with evidence (an ordinary `BusinessDocument`, so retention and legal hold apply), filings through the existing workflow engine, and a risk register. The decision worth knowing: unlike equipment servicing, the next occurrence is counted **from the due date by default**, because a late tax return does not move the DGI's quarters — but `schedule_basis` can be set to `completion` for a licence, which genuinely does run a year from renewal. One rule cannot serve both; getting it wrong is invisible for one cycle and a year adrift by the fourth. Residual risk scores are entered by a person and never derived from attached controls — a control on paper lowers nothing, and auto-lowering manufactures a reassuring number nobody chose. |
| 19 | BI & analytics | **B** | Reports and aging exist; no executive dashboard, KPI set, profitability or forecasting. |
| 20 | Workflow & approval engine | **A** | **Built 2026-08-16.** Workflows, ordered steps, instances, assignments and immutable decisions. Sequential, parallel and numeric quorum; role/department/user/owner/manager/creator approver modes resolved at assignment time; amount-based and field conditions as data; delegation with provenance; reject vs. changes-requested kept distinct; a step with no possible approver stalls rather than passing. `/actions` is the cross-module inbox. Trigger→action automation shipped separately (`AutomationRule`, ERP #20 automation half, below) and Documents now consumes the engine (`Approvable` + `TranslateDocumentWorkflowEvents`, restating generic events as `document.*`). See `docs/workflows.md`. **Admin screens built 2026-08-16** at /settings/workflows: copy-on-write versioning lets a workflow be edited while approvals are in flight (they finish under the rules they started with — refusing the edit would be a deadlock, since a workflow is discovered broken precisely when something is stuck in it); empty-approver steps warn rather than block; a workflow with no steps cannot be activated or made default, because the engine approves everything instantly on no steps and that is the one way an admin screen could become a rubber stamp. Every business is also seeded five default paths (DefaultWorkflows), with a per-currency requisition threshold, and opes:seed-workflows backfills businesses that predate it. `document_approvals` remains as the sales-specific mechanism, untouched on purpose. |
| 21 | Notifications | **A** (for what is in scope) | **Completed 2026-08-16 by extending the single existing path, not adding a second.** User-configurable rules on domain events, reusing `WorkflowConditions` and `WorkflowApprovers` unforked so conditions and recipient resolution mean the same thing here as in approvals — recipients are resolved when the event fires, never when the rule is written. Per-user preferences, quiet hours that *hold and release* rather than drop, digests, deduplication fingerprinted on record **and** message, and a delivery log so "I was never told" is answerable. Exactly one `critical` severity bypasses all four noise controls, and keeping it to one is load-bearing: a mute nobody can trust gets replaced by a mail-client filter that hides the critical ones too. **SMS and WhatsApp are out of scope by the user's decision** (no paid gateway); they are catalogued as unavailable and a rule naming one logs `channel_unavailable` rather than pretending. Adding one later is a channel class and two config keys. |
| 22 | Enterprise Documents | **B** | Part 1 above. |
| 23 | E-signature | **B** | **Built 2026-08-16, inside Documents as the brief specified.** Sequential and parallel rounds, per-signer status, decline with reason, a public unauthenticated signing link resolved cross-tenant the same way `/v/{token}` verification already is. On completion, mints a `VerificationToken` the same way `DocumentIssuer` does on issue — no second verification system. **Missing: signature fields placed on the document itself (a signature block at a specific position in the text), reminders (needs a scheduled job — none exists yet for Documents), and an admin screen** — requesting a round is API/service-only today. |
| 24 | Audit & governance | **A** | **Completed 2026-08-16.** The capture existed and observed 9 models; nothing could read it, nothing recorded reads, and permission grants left no trace at all. Now: one `Audit` write path the existing observer delegates to, coverage extended to **45 models** on the rule "money, permissions, or a person's record, and only where it is mutable"; a `subject_label` so a deleted record still reads as itself, which is the case the log is kept for; an audit screen and a per-record history panel; and a governance screen reporting segregation-of-duties conflicts from what people *actually hold*, including hand-made grants. Reads are logged narrowly — one named person's confidential record, an explicitly restricted document, or an export — windowed at one row per person per record per 15 minutes, except exports, which are never grouped because two exports are two copies loose in the world. Contents are never copied into the log. Deliberately **not** a switchable module: `Modules::forAbility` denies through `Gate::before`, so a switchable audit lets a business disable its own trail, and the person with the motive holds `settings.update`. **Known gap: no retention policy**, and this roughly triples the log's growth. |
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
