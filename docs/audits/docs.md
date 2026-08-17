# Documentation audit — 2026-08-17

Read-only audit of documentation against the working tree. No fixes applied.
Method: `config/modules.php` (26 modules) and `config/opes.php` navigation
(48 entries) compared against `App\Support\Guides::CATALOGUE` (28 guides);
guide bodies grepped for named abilities/routes and diffed against
`App\Support\Permissions::CATALOGUE` (170 abilities) and the route files;
`docs/API.md` endpoint list diffed against `php artisan route:list` for
`api/v1` (90 routes); marketing blades read against shipped services;
`docs/HANDOVER.md`, `docs/GAP-ANALYSIS.md`, `docs/PRODUCTION-READINESS.md`
checked for claims the tree contradicts; deploy docs checked for the VPS
environment (ClamAV, scheduler, queue).

---

## 1. Features vs guides

### 1.1 Coverage arithmetic

- 26 modules in `config/modules.php`; 48 navigation entries in `config/opes.php`.
- 28 guides catalogued in `app/Support/Guides.php`; all 28 files exist in
  `resources/guides/` and the catalogue↔file pairing is clean in both
  directions (the test-enforced invariant holds).
- But the catalogue skews heavily toward the *newest* features. The oldest and
  most-used modules — the ones every business touches on day one — have **no
  guide at all**.

### 1.2 Shipped modules with NO guide (15)

| Module (modules.php key) | What is undocumented |
|---|---|
| `sales` | Invoices, quotations, proformas, receipts, credit notes, voiding, payments — the core of the product. No guide. |
| `customers` | The contact book, balances, statements, collections/credit control. No guide. |
| `deals` | The pipeline itself (only `leads` — the front half — has a guide). |
| `products` | Items, pricing, stock adjustments, stocktakes, traceability (`track-view`/`track-manage`/`reserve` abilities). Only `replenishment` is covered. |
| `stock_locations` | Multi-location stock and transfers. |
| `expenses` | Supplier bills, expense claims (`claim-view/create/reimburse`), TVA reclaim. |
| `accounting` | The SYSCOHADA chart, journals, ledgers, statements. `closing-the-books` covers periods/closing only, not day-to-day accounting. |
| `banking` | Statement import and reconciliation. |
| `hr` (partial) | Employee records, contracts, allowances, leave. `attendance-and-reviews` covers positions/attendance/reviews only; leave and the employee file are unguided. |
| `payroll` | CNPS, IRPP, employer charges, run/approve/pay/void. Nothing. |
| `forms` | Shareable forms and responses. |
| `events` | Ticketing and door scanning. |
| `loyalty` | Points, cards, redemptions. |
| `vip` | Paid tiers and membership billing. |
| `reports` | Reports and the executive view. |
| `partners` | The whole secretariat programme — client book, card issuing, commission ledger. Nothing, despite being a paid commercial programme with rates published on the marketing site. |

(That is 16 rows; `hr` counted as partial → 15 full absences.)

The Guides.php header says "**Every feature gets a guide.** A feature nobody
can find out how to use is not finished" — by its own standard, the platform
core is unfinished. The test suite enforces guide↔file pairing but nothing
enforces module↔guide pairing, which is why this hole is invisible to CI.

### 1.3 Hardened verticals — guides vs newest capabilities

Checked against the hardening commits (`cb15fde`, `f3d2026`) and the models/
services in the tree:

| Guide | Covered | Missing |
|---|---|---|
| `insurance.md` | renewals (3 mentions), endorsements (3), instalments (2) | Adequate. |
| `logistics.md` | manifests (13), waybills (4) | **Rate cards: 1 passing mention** despite `app/Services/Logistics/RateCards.php` and `FreightRate` being a full feature. Thin. |
| `property-management.md` | landlord (8), landlord statements (3) | **Rent reviews: 0 mentions** despite `app/Models/TenancyRentChange.php` and `Services/Estate/Tenancies.php` shipping the feature. |
| `sales-orders.md` | returns (7), backorders (2), credit (4) | Adequate on returns; credit-control/collections has no dedicated treatment. |

Cross-cutting: `app/Support/CollectionsQueue.php` (credit control / chasing
overdue receivables) has no home in any guide.

### 1.4 Navigation screens with no guide

`calendar`, `imports`, `scan` (quick action), `executive` view, `banking`,
`stock` value screen — all reachable from nav, none documented.

---

## 2. Stale guide claims

Good news: this is the cleanest area audited.

- Every dotted ability name mentioned in `resources/guides/*.md` resolves
  against `Permissions::CATALOGUE`, config/opes.php or the route files.
  The only non-resolving tokens are merge-field placeholders
  (`company.name`, `company.address`, `customer.name`, `employee.job`,
  `project.code`) and one deliberate negative
  (`compliance-and-risk.md:93` — "There is no `compliance.approve`"), which
  is true.
- No guide references a route or screen that no longer exists.
- No numeric figures (rates, fees) found in guides that contradict config.

---

## 3. API documentation

- `php artisan opes:export-openapi` (`app/Console/Commands/ExportOpenApi.php`)
  is **generated from the router**, so it cannot drift on paths — by
  construction it covers all 90 `api/v1` routes. Its hand-maintained
  `$summaries` table is the only drift surface; adding a route without prose is
  visible in review as designed.
- `docs/API.md` (1,372 lines, hand-written, last touched 2026-08-16) is
  **missing 9 live endpoints**:
  - `/v1/accounting/journal`, `/v1/accounting/trial-balance`,
    `/v1/accounting/income-statement`, `/v1/accounting/balance-sheet`,
    `/v1/accounting/cash-flow`
  - `/v1/document-templates/{id}/publish`, `/v1/document-templates/{id}/unpublish`
  - `/v1/library/comments/{id}/reopen`
  - `/v1/partners/commissions`
  No documented endpoint is dead (zero entries in API.md absent from the
  router), so the drift is one-directional: additions not written up.
- **The four verticals have no API at all** — `routes/api.php` contains no
  insurance, orders, logistics or estate routes. That is a product-surface
  gap rather than a docs gap, but neither API.md nor the OpenAPI export can
  say so, and an integrator reading "the same records the web app works on"
  (API.md line 3) will assume otherwise.

---

## 4. Marketing pages vs reality

Checked `resources/views/marketing/` (home, features, pricing, faq, partners,
about).

Verified TRUE (claims that could have been false but are backed by code):
- Offline mode / invoice-number leasing → `DocumentNumbers`, `NumberLease`,
  service worker in `resources/js/app.js`. Real.
- Mobile-money payment → `MtnMomoGateway`, `OrangeMoneyGateway`,
  `SubscriptionBiller`, webhook controller. Real.
- Partner commercial terms → single source in `config/opes.php['partners']`
  (500 XAF card fee, 10% commission, 10,000 XAF payout floor), read by both
  the marketing page and the biller. Cannot drift by design.
- Verticals present on features page (9 mentions across insurance/property/
  logistics/manufacturing/sales-orders).

Gaps (shipped features absent from marketing):
- **Loyalty/VIP: one passing mention** on the features page despite being two
  full modules with card designs (`docs/CARD-DESIGNS.md`).
- Recruitment (public job adverts → hire), procurement/RFQ, payables
  scheduling, global Ctrl-K search, document sharing links, and the audit/
  governance reporting are effectively unmarketed.
- No false claims found. The risk direction is under-selling, not lying.

Operational footnote: `config/opes.php` `demo.enabled` defaults **on**
(`OPES_DEMO_LOGINS`), and no deploy document instructs turning it off for
production — the config comment itself says off "is the right setting the
moment real businesses hold data".

---

## 5. HANDOVER.md and GAP-ANALYSIS.md staleness

- **`docs/HANDOVER.md` — current.** Stamped 2026-08-17 post-hardening
  (2,932 tests / 13,291 assertions), names the verticals, the hardening pass,
  ClamAV, audit retention. Its §0 "known-open" list is explicitly historical
  ("at time of writing") and superseded by the banner above it. Minor nit:
  the header still says branch `claude/documents-phase-1` while the repo sits
  on `main`.
- **`docs/GAP-ANALYSIS.md` — badly stale.** Dated 2026-08-16 but pre-dates
  most of that day's work:
  - Says the workflow engine is "Correct, and unbuilt" — it is built, with
    admin screens, copy-on-write versioning and `DefaultWorkflows` seeding.
  - Says fiscal periods, period closing, cost centres and cash-flow statement
    are "the real remaining holes" — all four landed (see HANDOVER §0 and
    `closing-the-books.md`).
  - Row 25 lists "Missing: branches, departments, fiscal periods, approval
    rules, workflow configuration" — departments, fiscal periods, approval
    rules and workflow config all exist (`departments.md` guide, workflow
    admin, `DefaultWorkflows`).
  - Row 24 flags "Known gap: no retention policy" — `AuditRetention.php`
    shipped (commit 9320c55).
  - One solitary mention of the verticals; the four launch verticals and
    their hardening are absent.
  A reader doing what the HANDOVER tells them to ("Read docs/GAP-ANALYSIS.md
  for the per-domain detail") gets a picture roughly 15 major features old.
- **`docs/PRODUCTION-READINESS.md` — obsolete.** Assessed 2 August 2026 at
  "932 tests, 163 routes"; the suite is now 2,932 tests. Verdict ("supervised
  pilot") predates Documents, workflow, the verticals and Phase-5 infra.
- `docs/PLAN.md` and `docs/DEPLOY-WINDOWS.md` untouched since 2026-07-31.

---

## 6. Deploy documentation for the VPS path

- **`CLAMAV_SOCKET` is documented nowhere a deployer will look.** It appears
  only in `docs/handoff/upload-gate.md` (a decision record). It is absent
  from `docs/DEPLOYMENT.md`, `docs/DEPLOY-NAMECHEAP.md`, `README.md` and
  `.env.example` — so a fresh VPS install silently runs with no virus
  scanning and no hint the gate can have one (`app/Support/UploadGate.php`
  reads it).
- Scheduler and queue **are** covered in `docs/DEPLOYMENT.md`: queue worker
  under supervisor with `queue:restart` on deploy (§ line ~156), the single
  cron `schedule:run` entry (~165), docker-compose splitting web/worker/
  scheduler/MySQL (~111), and the "brings the site up at the worst possible
  moment" checklist. `DEPLOY-NAMECHEAP.md` covers the cron-driven queue for
  cPanel. Adequate.
- README links all three deploy docs. Adequate as an index.
- Residual gaps beyond ClamAV: no mention in any deploy doc of the search
  daemon or OCR pieces named as "Phase 5 VPS infrastructure" in the HANDOVER;
  no instruction to set `OPES_DEMO_LOGINS=false` in production.

---

## 7. Found in passing (code, not docs)

- **`config/modules.php:320`** uses `BillOfMaterial::class` and
  `ProductionOrder::class` with **no `use` imports**. The config file has no
  namespace, so these resolve to `\BillOfMaterial` / `\ProductionOrder` —
  classes that do not exist — instead of `App\Models\...`. `::class` on an
  undefined name does not error, so the manufacturing module's model map is
  silently wrong: with manufacturing switched off, model-backed policy checks
  on BOMs and production orders are NOT denied the way every other module's
  are (the exact hole the `models` key exists to close, per the file's own
  header comment).

---

## Summary counts

| Area | Finding | Count |
|---|---|---|
| Modules with no guide | of 26 modules | 15 (+2 partial) |
| Vertical guides missing newest capability | rent reviews, rate cards, collections | 3 |
| Stale ability/route references in guides | | 0 |
| Live API endpoints missing from API.md | of 90 | 9 |
| API.md endpoints that no longer exist | | 0 |
| Verticals with API coverage | of 4 | 0 |
| False marketing claims | | 0 |
| Shipped features absent from marketing | notable | ~6 |
| Stale major claims in GAP-ANALYSIS.md | | 5+ |
| Deploy docs missing VPS env | CLAMAV_SOCKET, demo flag, search/OCR | 3 |
| Code bugs found in passing | modules.php missing imports | 1 |
