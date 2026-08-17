# OPES360 — Production Readiness Assessment

**Assessed:** 17 August 2026 · 2,932 tests, 13,291 assertions (post-hardening
gate — figure per `docs/HANDOVER.md`; the suite takes ~6 minutes and is run
before every commit) · 26 modules · 90 `api/v1` routes
**Verdict:** ready for **production on a properly provisioned VPS** — see
`docs/DEPLOY-VPS.md` for the runbook. The code-side launch blockers found by
the 2026-08-17 platform audit (`docs/audits/`) were closed in fix waves 1–3;
what remains is host configuration (mail, monitoring, backups) and the known
limits in §4, none of which is a data-integrity risk.

This document supersedes the 2 August assessment ("932 tests, 163 routes,
supervised pilot"). The product it described no longer exists: since then the
platform gained the entire Tier-1/Tier-2 ERP completion (fiscal periods and
closing, payables scheduling, procurement, traceability, HR/recruitment,
projects, fleet, contracts, compliance, service desk), the Documents platform
with versioning, e-signature, sharing, retention and watermarks, one workflow
engine for the whole product, notifications with quiet hours and digests,
audit to 45 models with retention, Phase-5 infrastructure (real PDF engine,
one upload gate with optional ClamAV, permission-filtered global search), and
four launch verticals (insurance broking, sales orders & delivery, transport
with public tracking, property management). `docs/GAP-ANALYSIS.md` holds the
per-domain register; `docs/handoff/*.md` (~30 documents) the decisions.

---

## 1. What the audit found, and what was fixed

A five-part read-only audit on 2026-08-17 (`docs/audits/{wiring,flows,bugs,
docs,production}.md`) swept routes, events, policies, dark machinery, money
paths and deployment assumptions. The fix plan is `docs/audits/PLAN.md`;
status there is kept current. As of this assessment:

- **Money wave — done.** The discount-ledger posting (P0), XAF rounding
  notes, the over-credit race and the API `unit_price` handling
  (commits `94bdf48`, `cc50bbc`).
- **Wave 1 (dark machinery gets its UI) and Wave 2 (events + wiring) — done**
  (`fb80b85`): expense-claim screens, fiscal-period close/reopen settings,
  project task board, workflow resubmit for stalled approvals, insurance
  default workflow, policies for previously uncovered module models,
  ability middleware on the API-token screen, nav links to orphaned screens.
- **Wave 3 (production readiness) — done** (`docs/handoff/wave3.md`):
  `CLAMAV_SOCKET` read through `config()` so it survives `config:cache`;
  `.env.example` completed (CLAMAV_SOCKET, OPES_DEMO_LOGINS, CONTACT_RECIPIENT,
  CSP toggles); branded 404/500/503/419 pages; the webhook sync-driver
  inline-retry hazard; unbounded kanban queries and the dashboard double
  query; the production lazy-loading decision (log, don't throw).
- **Tenancy** — every public-page tenancy hole closed with a trap for the
  next one (`f5ecdd8`).
- **Wave 4 (API + docs) — in progress.** See §4.

## 2. What is genuinely production-grade

Unchanged in character from the previous assessment, and now much broader:

**Tenant isolation.** Trait-applied global scope, fails closed, guarded by a
test walking every table with `company_id`; public verification pages resolve
*as* the subject's company. The 2026-08-17 sweep closed the remaining
public-page holes and added a regression trap.

**Financial integrity.** Issued documents immutable at the model layer;
row-locked payments; overpayment refused, offline conflicts surfaced;
auditable number leasing; and now **period closing enforced at
`Ledger::post()`** — the single posting path — with reopening as a deliberate,
logged act. Voids extourne; credit and debit notes post as true mirrors.

**One engine per concern.** One workflow/approval engine (copy-on-write
versioned, seeded defaults, `/actions` inbox), one notification path, one
audit write path over 45 models with a ten-year retention floor on financial
subjects (`app/Support/AuditRetention.php`), one upload gate
(`app/Support/UploadGate.php`) in front of every upload, one PDF wrapper, one
search index filtered by the searcher's own permissions.

**Authorisation.** Four layers (route middleware, policies, explicit
authorize in every mutating Livewire action, conditional rendering);
gates generated from the permission catalogue with drift tests; module
switch-off enforced in one `Gate::before`.

## 3. Host configuration still required before real users

These are unchanged in kind since 2 August because they need a host, not code.
`php artisan opes:doctor` checks all of them and exits non-zero.

1. **Mail transport** — `MAIL_MAILER=log` delivers password resets to a log
   file. Configure SMTP/SES/Postmark with SPF+DKIM; confirm with
   `opes:doctor --mail=you@yourdomain.com`.
2. **Error tracking and uptime monitoring** — nothing is configured; a
   production 500 is visible only in `storage/logs`.
3. **Backups with a rehearsed restore** — documented in
   `docs/DEPLOYMENT.md` §5 and `docs/DEPLOY-VPS.md`, not automated.
4. **The cron entry and a queue worker** — recurring invoicing, dunning and
   SLA sweeps exist *only* via the scheduler; queued mail exists only via the
   worker. A deploy that forgets either silently stops billing and delivery.
5. **`OPES_DEMO_LOGINS=false` and `CLAMAV_SOCKET`** — demo one-click logins
   default on for a fresh checkout and must be off in production; upload
   virus-scanning is a documented no-op until clamd is pointed at.

## 4. Known limits worth stating plainly

Drawn from `docs/audits/*` and `docs/handoff/*`; none blocks a launch, all
should be known by whoever operates it.

- **No API for the four verticals**, the service desk, manufacturing, or
  workflow-rule CRUD (`docs/audits/docs.md` §3). An integrator reading
  API.md's "the same records the web app works on" will over-assume.
- **15 core modules have no in-product guide** (sales, customers, products,
  accounting, payroll, expenses, banking, forms, events, reports, partners…) —
  the docs audit's largest finding.
- **ClamAV is optional by design** — without `CLAMAV_SOCKET` the gate falls
  back to extension + sniffed-MIME checks only.
- **SLA policy edits do not recompute deadlines on open tickets**
  (`docs/handoff/4.7-integration.md`) — the largest self-declared gap.
- **Statement aging uses today's balance**, not as-at-period balance, on both
  the customer and supplier side (no balance history is stored).
- **Audit pruning deletes rather than archives**, and legal hold covers
  documents only.
- **`preventLazyLoading` is off in production** (deliberate: log, don't
  throw) — future N+1 regressions need log review to surface.
- **CSP still needs `unsafe-eval`** (Alpine), inline styles stay allowed;
  stated in `App\Support\Csp`.
- **Thermal printing is Android-only**; iOS prints via the browser dialog.
- **Firefox/WebKit engine sweep still unrun** in this environment
  (`scripts/audit/engines.mjs`); Chromium is clean. Run it on a machine that
  can install them before open launch.
- **Sequence gaps and tax law** — number leasing produces auditable gaps;
  unverified against any specific jurisdiction.
- **Single-currency per company**; no FX handling in reporting.
- **Editing offline is deliberately unsupported** (creation works offline).

## 5. Recommended path to launch

| Step | Work | Rough size |
|---|---|---|
| 1 | **Provision the VPS** per `docs/DEPLOY-VPS.md` — cron, supervisor worker, clamd, demo logins off, caches, backups | 1 day |
| 2 | **Configure mail** and confirm with `opes:doctor --mail=…` | 1 day incl. DNS |
| 3 | **Error tracking + uptime monitoring + rehearsed restore** | 1–2 days |
| 4 | → **Production launch with real businesses** | — |
| 5 | Finish Wave 4 (vertical APIs, core-module guides) alongside | ongoing |
| 6 | **Engine sweep** (Firefox/WebKit) and a security review before open, unsupervised signup | 1 week |

The difference from the 2 August assessment is the verdict at step 4: this is
no longer "supervised pilot" territory. The suite is three times the size, the
audit fix waves closed everything the sweep rated a launch risk, and the money
paths are guarded at the single posting path rather than at the screens. What
keeps step 6 on the list is exposure, not integrity: open self-signup deserves
a pen test and the two unwatched browser engines deserve one run.

## 6. Test coverage summary

2,932 tests / 13,291 assertions, run against **both SQLite and MySQL 8.4 in
strict mode** (the dual-engine run exists because SQLite once accepted a
unique key MySQL could not create — see `docs/HANDOVER.md` §4). Beyond the
areas the previous assessment listed (tenancy, financial correctness, sync,
auth, page renders, authorisation with non-owner actors, offline numbering
and payments, document generation, CSP), the suite now also pins:

- **Query budgets** (`tests/Feature/QueryBudgetTest.php`) — a ceiling per
  screen plus the ten-times-the-rows rule that catches N+1s
- **The hostile-seed branding corpus** — 13 seeds × every role × both themes
- **Period closing** — posting into a closed period refused at the ledger
- **Workflow** — quorum, delegation, stall-not-pass on empty approver sets,
  copy-on-write versioning finishing in-flight approvals under old rules
- **Documents** — immutability with the filing allow-list outside the tamper
  hash, versioning, sharing, retention, signature rounds
- **Vertical hardening** — the industry-standard behaviours added in
  `cb15fde`/`f3d2026`
- **Guide↔catalogue pairing** — every catalogued guide file exists and vice
  versa (module↔guide pairing is *not* enforced; that is the §4 guide gap)

Accessibility (axe over every page in both schemes, WCAG 2.5.8 targets,
keyboard walk) and the Chromium page sweep continue to pass as before.
