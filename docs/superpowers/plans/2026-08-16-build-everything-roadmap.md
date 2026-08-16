# Build-everything roadmap

**Goal:** build every capability `docs/GAP-ANALYSIS.md` marks as **C** (does not
exist) or as a named hole inside a **B** (part-built), in an order where nothing
is built twice and nothing is built on sand.

**Source of truth:** `docs/superpowers/specs/2026-08-16-documents-master-spec.md`

This is a roadmap, not a plan. Each numbered item below gets its own plan file
under `docs/superpowers/plans/`, written immediately before it is built, in the
format the writing-plans skill requires. Each one produces working, tested,
shipped software on its own — no item leaves the product in a half state.

---

## The sequencing rule

The brief's own architecture dictates the order:

> Documents, Workflow, Notifications, Audit, Search, Automation, Storage,
> Reporting and AI are **platform services**. Business modules consume them.

So platform services are built before the business modules that would otherwise
grow their own. Every time this order is violated the product gains a duplicate
engine, which is the one thing the brief forbids outright.

Concretely: **the workflow engine precedes Documents §17–19, procurement
approvals, HR approvals and expense approvals.** Building Documents' approvals
first means writing the fourth approval mechanism in the product and then
deleting it.

---

## Phase 0 — finish what is open (in flight)

| # | Item | Why first |
|---|---|---|
| 0.1 | Documents permissions (`papers.share`, `papers.manage`, restricted enforced in the policy) | Already written and red on this branch. Everything else in Documents authorises through it |

## Phase 1 — foundations that unblock disproportionately

| # | Item | Unblocks |
|---|---|---|
| 1.1 | **Departments** as an entity (currently a free-text string on `employees`) | Documents §3/§9/§10/§20 · ERP #9 · #25 · approval routing by department |
| 1.2 | **Workflow & approval engine** — trigger → condition → action, stages, sequential/parallel/conditional/role/department/amount-based approval, delegation, reassignment | Documents §17–18 · Procurement approvals · Expense claims · HR · Contracts · Finance |
| 1.3 | **Domain event bus** — `document.*`, and the pattern every later module emits on | Documents §59 · Automation §54 · Notification rules §33 |
| 1.4 | **Projects** — projects, tasks, milestones, budgets, expenses, timesheets, profitability, resource allocation | ERP #11 (Tier 1) · Documents §9/§10/§36–37 · job costing |

## Phase 2 — Documents to completion

Everything in Part 1 of the gap analysis not blocked on absent infrastructure.

| # | Item |
|---|---|
| 2.1 | ~~Workspace screen~~ **Done** — search, kind/security/folder/tag filters, folder tree, five overview counters, confidentiality enforced via `BusinessDocument::scopeReadableBy()` so a restricted document is refused at the query, not filtered out after fetching. Guide: `documents-workspace.md` |
| 2.2 | ~~Library API~~ **Done** — `docs/API.md` §22. Introduced a `file` ability separate from `update`, since `update` correctly requires a draft and filing must work on an issued document |
| 2.3 | ~~Versions, locking and finalisation~~ **Done** — `docs/superpowers/plans/2026-08-16-documents-versions.md`. Finalisation was not rebuilt: issuance already does it. No API yet, noted in `docs/API.md` §23 |
| 2.4 | ~~Version comparison~~ **Done** — word-level diff (`VersionComparator`), with an API. Own commit, since the earlier plan carved comparison out separately from creation |
| 2.5 | ~~Comments, replies, mentions, resolution~~ **Done** — mentions are explicit user ids, never `@name` parsed out of text, and notify through the existing notification layer |
| 2.6 | ~~Document audit trail and activity timeline~~ **Done** — no new table; merges the existing `activity_log`, versions and comments. `BusinessDocument` added to the audited-model list |
| 2.7 | ~~Workflow binding~~ **Done** — `Approvable` + `EmitsDomainEvents` on `BusinessDocument`; `TranslateDocumentWorkflowEvents` restates the engine's generic events as `document.*`. Caught and fixed a real bug: the engine was emitting through the `WorkflowInstance` wrapper rather than the actual subject, so no subject-specific listener could ever match. See `docs/workflows.md` |
| 2.8 | ~~E-signature~~ **Done** — sequential/parallel, decline, public signing link resolved cross-tenant like `/v/{token}`, completion mints a `VerificationToken` via the existing issuer pattern rather than a second verification system. **Missing: reminders (needs a scheduler — none exists for Documents yet) and an admin screen; a caught bug** — `create()` doesn't backfill DB column defaults into the in-memory model, so `status` read as null immediately after creating a signature despite the row correctly saying 'pending'; fixed by setting it explicitly rather than trusting the migration default |
| 2.9 | External sharing — secure links, expiry, password, revocation, access log (§21) |
| 2.10 | Retention, legal hold, lifecycle states, controlled disposal (§31–32) |
| 2.11 | Per-type document numbering (§25) |
| 2.12 | Templates as data — create, edit, version, publish, permission (§8) |
| 2.13 | Dynamic ERP field registry, modules registering their own fields (§7) |
| 2.14 | Related content, dossiers, packages, bundles, checklists (§36–40) |
| 2.15 | My Actions centre and document alerts (§33–34) |
| 2.16 | Bulk operations (§68) |
| 2.17 | Watermarks and print control (§72–73) |
| 2.18 | Documents administration screens (§46) |
| 2.19 | Analytics and admin dashboard (§74–75) |
| 2.20 | Documents panel on every remaining ERP record (§66) |
| 2.21 | Offline document handling and the mobile interface (§50–51) |
| 2.22 | Extension points for other modules (§58) |

## Phase 3 — Tier-1 ERP holes

| # | Item | Gap-analysis line |
|---|---|---|
| 3.1 | Fiscal periods, period closing, cost centres, cash-flow statement | #1 |
| 3.2 | Account transfers, cash forecasting | #2 |
| 3.3 | Debit notes, customer statements, collections workspace | #3 |
| 3.4 | AP payment scheduling, supplier statements, supplier reconciliation | #4 |
| 3.5 | Purchase requisitions, RFQ, supplier quotations, procurement approvals | #5 |
| 3.6 | Batch/lot tracking, serial numbers, expiry dates, stock reservations | #6 |
| 3.7 | Leads, deal activities, sales forecasting | #7 |
| 3.8 | Employee expense claims, reimbursement, cost-centre allocation | #8 |
| 3.9 | Positions, attendance, performance, recruitment | #9 |
| 3.10 | Asset transfers, maintenance, asset locations | #12 |

## Phase 4 — Tier-2 modules

| # | Item |
|---|---|
| 4.1 | Contracts management, built **on** Documents (#17) |
| 4.2 | Compliance & risk (#18) |
| 4.3 | BI & analytics — executive dashboard, KPIs, profitability, forecasting (#19) |
| 4.4 | Notification rules, escalations, SMS and WhatsApp channels (#21) |
| 4.5 | Audit & governance — login audit, permission-change audit, export audit, governance view (#24) |
| 4.6 | Administration — branches, fiscal periods, approval rules, workflow config (#25) |
| 4.7 | Service management — requests, tickets, SLA, field service, work orders (#16) |
| 4.8 | Logistics & fleet (#15) |
| 4.9 | Supply chain — demand planning, replenishment, supplier performance (#14) |
| 4.10 | Manufacturing — BOM, work orders, MRP, production costing (#13) |

## Phase 5 — blocked on a decision, not on effort

These cannot be built without choosing and paying for infrastructure. Each is
a question for the product owner, not a task:

| Item | The decision needed |
|---|---|
| Rich document editor (§6) | Which editor — TipTap/ProseMirror, CKEditor, or a Livewire-native one |
| Server-side PDF (§24) | Which engine — Browsershot/Chromium, Gotenberg, or stay with `window.print()` |
| DOCX import/export (§23–24) | Whether at all. Weakest value-per-effort item in the brief |
| Preview tier (§22) | Conversion service for office formats |
| Content search index (§26) | Meilisearch, Typesense, or MySQL full-text |
| OCR (§27) | Tesseract self-hosted, or a cloud OCR API |
| AI assistant and AI search (§41–42) | Provider and budget; permission-filtered retrieval design |
| Translation (§43) | Provider |
| Realtime co-editing (§14, §16) | Websocket tier, plus OT/CRDT. **Recommended against**; locking (2.3) covers most of the value |
| Malware scanning (§61) | ClamAV or a cloud scanner |

Phase 5 is deliberately last and deliberately unstarted. Everything above it is
buildable today with no new dependency.

---

## Conventions every plan below inherits

From `docs/HANDOVER.md`, and non-negotiable:

- Spec → plan → task-by-task with tests, committing each step
- **Full suite before every commit.** ~6 minutes; run it in the background
- `php artisan opes:export-schema` before every commit that adds a migration —
  tests run SQLite, production is MySQL, and only this catches the difference.
  Needs `mysqldump` on PATH:
  `export PATH="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"`
- PHP is not on PATH by default:
  `export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:$PATH"`
- **Every feature ships with its guide**, added to `App\Support\Guides` and
  `resources/guides/`, in the same commit as the feature. A feature nobody can
  find out how to use is not finished; `GuidesTest` enforces the pairing
- Never loosen an assertion to make a build pass; fix the generator
- Semantic colours (`positive`/`warning`/`negative`) are not brandable
- No new Composer packages without saying why
- Reuse the existing design system exactly: `x-ui.panel`, pill filters, `card`,
  the token palette
- Regression gate is per-commit: the six Papers test files must pass at every
  step of any Documents work

---

## Progress

- [x] 0.1 Documents permissions — `papers.share`/`papers.manage`, restricted enforced in the policy
- [x] 1.1 Departments — entity, nested, backfilled from the free-text column
- [x] 1.2 Workflow & approval engine — `docs/workflows.md`. Automation triggers
      and the admin screen are carved out into 1.3 and 4.6 respectively
- [x] 1.3 Domain event bus + automation rules — `docs/automation.md`
- [x] **In-product documentation** — `/guides`, one guide per feature, with a
      test that fails if a guide and its catalogue entry disagree in either
      direction. **Every feature built from here on adds its guide in the same
      commit.**
- [x] 1.4 Projects — entity, workspace screen, guide. **No API yet** — noted
      in `docs/API.md` §22 rather than silently left out. Documents §9/§10
      project folders still wait on Documents phase 2, not on Projects — the
      entity they needed now exists
- [ ] 2.1–2.22 Documents to completion
- [ ] 3.1–3.10 Tier-1 ERP holes
- [ ] 4.1–4.10 Tier-2 modules
- [ ] 5 Blocked on decision — awaiting the product owner
