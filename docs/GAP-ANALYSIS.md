# Gap analysis — what is built, part-built, and not built

**Date:** 2026-08-17 (refreshed after the Documents completion pass — creation
routes, the document.* event stream, extension points, multilingual
templates, department/project dossiers, background bundling, and the
rich-editor/soft-lock wave that shipped just before it; originally written
2026-08-16)
**Measured against:** `docs/superpowers/specs/2026-08-16-documents-master-spec.md`
(the Documents master specification and the ERP completeness checklist inside
it).

**Method.** Every line below was checked against the working tree on
2026-08-17 — models, migrations, services, Livewire components, routes,
`config/modules.php`, `app/Support/Permissions.php` and `docs/API.md`. Nothing
here is inferred from the brief or from what a module's name suggests.

**Legend.** **A** already exists, reuse it · **B** partly exists, extend it ·
**C** does not exist.

---

## Part 1 — The Documents module

Phase 1 of `docs/superpowers/plans/2026-08-15-documents-core.md` is delivered,
and the 2026-08-16 completion pass (bulk actions, watermarks, analytics, daily
reminders) closed most of what the first draft of this document listed as
"not built". The tables follow the master spec's own section numbers;
struck-through rows shipped after the original 2026-08-16 assessment.

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

Everything that had no missing prerequisite is now built. What remains is
either a genuine infrastructure gap or an argued-against trade-off — both
recorded so the decision is visible, not silent.

**Deferred for a missing prerequisite — the dependency does not exist in the
codebase or on this box at all:**

| § | Capability | Missing prerequisite |
|---|---|---|
| 22 | Preview (DOCX, office formats) | No conversion tier. PDF preview is no longer blocked — a `BusinessDocument` and its bundles already render as PDF — but Office-format preview still needs a converter |
| 23–24 | DOCX import/export | No DOCX engine |
| 27 | OCR | No Tesseract on this box yet — a VPS item, not a code gap |
| 41–42 | AI assistant and AI search | No AI provider configured |
| 43 | Translation workflows | No translation service |

**Shipped in the 2026-08-17 completion pass:**

| § | Capability | Where |
|---|---|---|
| 6 | Rich document editor | Tiptap on draft documents, `app/Livewire/Papers/Edit.php` — autosave, version snapshots at a sane cadence, sanitised HTML in and out |
| 70 (extended) | Soft edit-locking | A second editor gets read-only with "X is editing"; a stale lock (crashed tab, 90s) is claimable; a save without the lock is refused. This is deliberately NOT §14 — see below |
| 5 | Creation routes — duplicate, from an ERP record, from a workflow step, from an automation rule | `DocumentComposer::duplicate()`, `RecordDocumentComposer`, the `compose_document` workflow step type, the `compose_document` automation action — all four terminate in the one composer, never a parallel creator |
| 54, 59 | The `document.*` event stream | Audited against the catalogue the way the earlier platform audit checked `hr.*` — found and fixed the same drift class: events catalogued but never emitted (`document.created`, `.published`, `.shared`, `.version.created`, `.review.requested`) and real moments with no name at all (`.voided`, `.commented`, `.version.restored`, `.legal_hold.placed/lifted`). A parity test now proves every catalogued `document.*` name has a real emission site |
| 58 | Extension points for other modules | `DocumentTypeRegistry`, mirroring `DocumentFieldRegistry`'s pattern — a module registers a kind without Documents importing it. Automation triggers/actions already reached `document.*` generically; confirmed, not rebuilt |
| 44 | Multilingual templates | The `language` column finally does something — per-template body variants, a language picker on Compose when a template has more than one, falling back to the default body rather than ever composing blank |
| 9, 10 | Department and project dossier folders | Departments and Projects are both real entities now, unblocking this row. Built the same way every other dossier is — a query over existing links, never a stored folder that could drift from reality |
| 60 | Background processing for large files | ZIP bundling over 20 files/20MB and search reindex-on-upload both move to a queue when one exists, and both still complete synchronously and correctly under `QUEUE_CONNECTION=sync` — the same fail-safe pattern the webhook fix established |
| 53 | Full document view audit | `Papers/Show.php` gained the read-only Details and Recent activity panels its backing services (`DocumentActivity`, shares, signatures, retention) already supported but never surfaced. Related-records, permissions and attachments panels are named in the handoff as needing real new infrastructure, not stubbed |
| ~~17–18, 24, 26, 30, 33, 39, 46, 68, 71–75~~ | Workflow engine, PDF export, search, audit trail, alerts, PDF bundles, admin screens, bulk actions, watermarks, analytics | **Shipped 2026-08-16**, unchanged since the last pass |

**Assessed, not built:**

- **§50–51 offline document handling.** `SyncEngine` has no binary-transfer
  story and `BusinessDocument` lacks the sync-sequence columns `Document`
  has. Assessed rather than deferred by default: papers are not usually
  created at the point of poor connectivity the way a sale is, so the
  argument for wiring this is weaker than it looks. Revisit if evidence says
  otherwise.

**Argued against rather than merely deferred:**

- **§14 realtime co-editing.** Still the right call, and now tested against
  a real alternative rather than a hypothetical one: soft locking (above)
  ships instead, and buys most of the value — nobody's edit is silently
  overwritten — for a fraction of the cost of OT/CRDT plus a websocket tier,
  on an offline-first PWA where two people editing one document at the same
  second is rare. The documented upgrade path is Laravel Reverb, self-hosted
  on the VPS, with Tiptap-collab as the step after presence — feasible now
  that a VPS exists, still not worth it until locking's value is proven
  insufficient.
- **§23–24 DOCX import/export.** Unchanged — the weakest value-per-effort
  item in the brief.

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
| 13 | Manufacturing | **A** | **Built 2026-08-16/17.** Bills of material, production orders, backflush to the stock ledger (`app/Services/Manufacturing/Production.php`, `manufacturing` module in `config/modules.php`). A follow-up fix (commit `645e9d9`) imported the `BillOfMaterial`/`ProductionOrder` model classes the module map points at, closing a silently-wrong policy denial. See `docs/handoff/manufacturing-integration.md`. |
| 14 | Supply chain | **A** | **Built 2026-08-16/17** on top of the existing reorder levels: supplier links per item with lead times, a replenishment read model (days of cover, "will run out before delivery"), and accepting a suggestion raises a **draft requisition** through the existing procurement path — nothing here can mint a PO or commit money (`app/Support/Replenishment.php`, `app/Services/Procurement/Replenisher.php`, `docs/handoff/supply-chain-integration.md`). |
| 15 | Logistics / fleet | **A** | **Built 2026-08-16, as an extension of fixed assets rather than a module of its own** — nine of the twelve things a fleet feature is usually asked for already existed on assets (register, location, driver, reassignment with history, service schedule, completion, cost-via-expense, disposal). What was genuinely missing: vehicle papers as a one-to-one extension of the asset, trips, fuel logs, expiry alerts, and servicing scheduled by **distance**. That last is the real addition, and it extends `AssetMaintenance` and `AssetServicing` rather than paralleling them, so there is still one outstanding list and one overdue count. The odometer is derived from the highest recorded reading, never stored — a stored counter goes stale the first time a mistyped trip is corrected, and the schedule would then count from a number nobody could reproduce. A fuel log holds no amount at all: it reads the expense every time. |
| 16 | Service management | **A** (+ walk-in triage 2026-08-16: the company's existing printed QR opens a public mobile intake at /triage/{token}; tickets are created only through TicketDesk with the SLA running, a visitor's "very urgent" caps at high because urgent is the business's promise to make, and the page never mints Contacts — an open public page must not fill the customer book with junk) | **Built 2026-08-16.** Tickets, visits, parts, and SLA policies with a working-hours calendar. Time is logged to the **existing** project timesheet (`service_job_id` added, `project_id` made nullable) rather than a second one; a maintenance visit completes the existing `AssetMaintenance`; billing drafts an ordinary invoice `Document` and holds a link, never a copy. SLA deadlines are stored as absolute instants already walked through the calendar, and are always *recomputed* as `opened_at + target + paused` rather than nudged, so pause/escalate/reopen are idempotent instead of drifting. The clock stops while waiting on the customer and while resolved. (`Ticket` remains event ticketing — an easy misreading of the model list.) |
| 17 | Contracts management | **A** | **Built 2026-08-16, on Documents as the brief specified** — the signed paper is a `BusinessDocument`, so versioning, retention, legal hold, sharing and e-signature already apply; contracts carry no number of their own. The centre of the design is `notice_by`, the last day notice can be served: stored rather than derived so it can be indexed, rewritten on every save so it cannot drift from `ends_on`, and refused outright when a contract auto-renews with an end date but no notice period, because that combination promises a warning that can never be given. `ContractWatch` keeps *deadline coming*, *deadline gone*, and *deadline gone on a self-renewing contract* as three separate lists rather than one that gets skimmed. |
| 18 | Compliance & risk | **A** | **Built 2026-08-16.** Statutory obligations with evidence (an ordinary `BusinessDocument`, so retention and legal hold apply), filings through the existing workflow engine, and a risk register. The decision worth knowing: unlike equipment servicing, the next occurrence is counted **from the due date by default**, because a late tax return does not move the DGI's quarters — but `schedule_basis` can be set to `completion` for a licence, which genuinely does run a year from renewal. One rule cannot serve both; getting it wrong is invisible for one cycle and a year adrift by the fourth. Residual risk scores are entered by a person and never derived from attached controls — a control on paper lowers nothing, and auto-lowering manufactures a reassuring number nobody chose. |
| 19 | BI & analytics | **B** | Reports, aging, and the executive dashboard (landed 2026-08-16, `executive` navigation view) exist; sales forecasting reads off the pipeline (Tier-1 #7). Still missing: per-module profitability and a configurable KPI set. |
| 20 | Workflow & approval engine | **A** | **Built 2026-08-16.** Workflows, ordered steps, instances, assignments and immutable decisions. Sequential, parallel and numeric quorum; role/department/user/owner/manager/creator approver modes resolved at assignment time; amount-based and field conditions as data; delegation with provenance; reject vs. changes-requested kept distinct; a step with no possible approver stalls rather than passing. `/actions` is the cross-module inbox. Trigger→action automation shipped separately (`AutomationRule`, ERP #20 automation half, below) and Documents now consumes the engine (`Approvable` + `TranslateDocumentWorkflowEvents`, restating generic events as `document.*`). See `docs/workflows.md`. **Admin screens built 2026-08-16** at /settings/workflows: copy-on-write versioning lets a workflow be edited while approvals are in flight (they finish under the rules they started with — refusing the edit would be a deadlock, since a workflow is discovered broken precisely when something is stuck in it); empty-approver steps warn rather than block; a workflow with no steps cannot be activated or made default, because the engine approves everything instantly on no steps and that is the one way an admin screen could become a rubber stamp. Every business is also seeded five default paths (DefaultWorkflows), with a per-currency requisition threshold, and opes:seed-workflows backfills businesses that predate it. `document_approvals` remains as the sales-specific mechanism, untouched on purpose. |
| 21 | Notifications | **A** (for what is in scope) | **Completed 2026-08-16 by extending the single existing path, not adding a second.** User-configurable rules on domain events, reusing `WorkflowConditions` and `WorkflowApprovers` unforked so conditions and recipient resolution mean the same thing here as in approvals — recipients are resolved when the event fires, never when the rule is written. Per-user preferences, quiet hours that *hold and release* rather than drop, digests, deduplication fingerprinted on record **and** message, and a delivery log so "I was never told" is answerable. Exactly one `critical` severity bypasses all four noise controls, and keeping it to one is load-bearing: a mute nobody can trust gets replaced by a mail-client filter that hides the critical ones too. **SMS and WhatsApp are out of scope by the user's decision** (no paid gateway); they are catalogued as unavailable and a rule naming one logs `channel_unavailable` rather than pretending. Adding one later is a channel class and two config keys. |
| 22 | Enterprise Documents | **B** | Part 1 above. |
| 23 | E-signature | **B** | **Built 2026-08-16, inside Documents as the brief specified.** Sequential and parallel rounds, per-signer status, decline with reason, a public unauthenticated signing link resolved cross-tenant the same way `/v/{token}` verification already is. On completion, mints a `VerificationToken` the same way `DocumentIssuer` does on issue — no second verification system. **Missing: signature fields placed on the document itself (a signature block at a specific position in the text), reminders (needs a scheduled job — none exists yet for Documents), and an admin screen** — requesting a round is API/service-only today. |
| 24 | Audit & governance | **A** | **Completed 2026-08-16.** The capture existed and observed 9 models; nothing could read it, nothing recorded reads, and permission grants left no trace at all. Now: one `Audit` write path the existing observer delegates to, coverage extended to **45 models** on the rule "money, permissions, or a person's record, and only where it is mutable"; a `subject_label` so a deleted record still reads as itself, which is the case the log is kept for; an audit screen and a per-record history panel; and a governance screen reporting segregation-of-duties conflicts from what people *actually hold*, including hand-made grants. Reads are logged narrowly — one named person's confidential record, an explicitly restricted document, or an export — windowed at one row per person per record per 15 minutes, except exports, which are never grouped because two exports are two copies loose in the world. Contents are never copied into the log. Deliberately **not** a switchable module: `Modules::forAbility` denies through `Gate::before`, so a switchable audit lets a business disable its own trail, and the person with the motive holds `settings.update`. **Retention shipped 2026-08-16** (`app/Support/AuditRetention.php`, `opes:prune-audit` scheduled nightly): pruning with a ten-year floor on financial subjects, so the growth concern is answered without letting money history be shredded. Residuals recorded in `docs/handoff/audit-retention.md` — pruned rows are deleted, not archived, and legal hold covers documents only. |
| 25 | Administration | **B** | Companies, users, roles, permissions, numbering, currencies, taxes, module switches, branding. Departments (entity, nested, archivable), fiscal periods with closing, and workflow/approval configuration (`/settings/workflows`, with copy-on-write versioning and `DefaultWorkflows` seeding) all landed 2026-08-16. **Still missing: branches.** |

### The brief's three concerns, answered

1. **"A proper accounting engine."** Present, and complete against the brief —
   double-entry, GL, trial balance, income statement, balance sheet, AR/AP
   aging, bank reconciliation and tax declarations, **plus** the four holes the
   first draft of this document named: fiscal periods and period closing
   (enforced at `Ledger::post()`, the single posting path), cost centres on
   journal lines, and a direct-method cash-flow statement. All landed
   2026-08-16 — see `resources/guides/closing-the-books.md`.
2. **"One workflow engine."** **Built** (`app/Services/Workflow/`, Tier-2 #20
   above): sequential/parallel/quorum steps, conditions as data, delegation,
   admin screens at `/settings/workflows` with copy-on-write versioning, and
   `DefaultWorkflows` seeding with an `opes:seed-workflows` backfill. Documents
   §17–18 consume it (`Approvable`) rather than growing a fourth approval
   mechanism, which was the risk this concern named.
3. **"The Documents platform."** Substantially built; Part 1 says exactly
   what is in and what is deliberately out. The remaining "C" items are those
   waiting on infrastructure (websockets, AI, DOCX) or argued against.

---

## Part 3 — The four launch verticals

Not in the original brief, built 2026-08-16 and hardened 2026-08-16/17
(commits `9d8b4fe`, `cb15fde`, `f3d2026`). Each is a thin layer over the
platform, **off by default** per business, with its money-committing act split
into its own ability. Plan: `docs/superpowers/plans/2026-08-16-industry-verticals.md`.

| Vertical | Verdict | Where / what |
|---|---|---|
| Insurance broking | **A** | Policies, claims, renewals, endorsements, premium instalments (`app/Services/Insurance/{Policies,Claims}.php`). Hardened in `f3d2026`. Guide: `resources/guides/insurance.md`. |
| Sales orders & delivery | **A** | Orders, fulfilment, backorders, returns and credit (`app/Services/Orders/{Fulfilment,Returns,OrderNumbers}.php`). Guide: `resources/guides/sales-orders.md`. |
| Logistics / transport | **A** | Manifests, waybills, freight rate cards, public tracking (`app/Services/Logistics/{Dispatch,RateCards}.php`, `FreightRate`). Guide: `resources/guides/logistics.md` — thin on rate cards per the docs audit. |
| Property / estate | **A** | Landlords with statements, tenancies, rent reviews (`app/Services/Estate/{Landlords,Tenancies}.php`, `TenancyRentChange`). Guide: `resources/guides/property-management.md` — silent on rent reviews per the docs audit. |

Known limit shared by all four: **no API** — `routes/api.php` carries no
insurance, orders, logistics or estate routes (docs audit §3; Wave 4 of
`docs/audits/PLAN.md`).

---

## What this changes about the plan

The three items the first draft put here are all resolved: the workflow engine
is built (and did come before Documents §17–18, so the product has one approval
mechanism, not four); Projects exists as a Tier-1 domain; and departments are
an entity, backfilled from the old free-text column. What remains, in order of
consequence, is Wave 4 of `docs/audits/PLAN.md`:

1. **API surface** — the four verticals, service desk and manufacturing have
   no API routes; workflow-rule CRUD is screen-only.
2. **Guides for the platform core** — 15 modules (sales, customers, products,
   accounting, payroll, expenses, banking, forms, events, reports, partners…)
   ship with no guide, and the vertical guides miss their newest capabilities
   (rent reviews, rate cards).
3. **Branches** (Administration #25) — the one named admin gap left.

The rest of the brief's architectural rules are already being followed:
Documents extends `business_documents` rather than claiming `documents`,
references ERP rows instead of copying them, reuses the media/upload path, the
auth system, the QR verification infrastructure, the notification layer and the
existing design system, and creates no competing transactional type.
