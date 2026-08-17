# Production readiness audit — read-only

**Date:** 2026-08-17. Scope: `app/`, `resources/`, `config/`, `database/migrations/`, `routes/`, `tests/`, `docs/handoff/`. No fixes applied.

---

## 1. TODO / FIXME / HACK / placeholder sweep

**Actionable markers found: 0.** No literal `TODO`, `FIXME`, `HACK` or `WIP` comments exist in `app/` or `resources/`.

All ~50 "placeholder" hits are legitimate domain vocabulary, not stubs:

- `app/Support/DocumentTemplates.php` (lines 15–1363, ~40 hits) — form-field `placeholder` attributes and `{{ placeholder }}` template tokens; this is the document-template engine's data model.
- `app/Services/DocumentComposer.php:45,51,124,165` — the code that fills those placeholders.
- `app/Support/CardCatalog.php:20`, `app/Support/BlogPosts.php:34`, `app/Livewire/Papers/Compose.php:20` — descriptive comments about swapped preview content.
- `app/Http/Controllers/Admin/PlatformAdminsController.php:52` — deliberate unusable password placeholder for invited admins (correct pattern).
- `app/Console/Commands/Doctor.php:122` — a *check* that MAIL_FROM_ADDRESS is not left as a placeholder (good, not a gap).

Verdict: clean. The codebase does not carry deferred-work markers; deferred work lives in `docs/handoff/` instead (see §8).

## 2. N+1 risk on the heaviest screens

`Model::preventLazyLoading(! isProduction())` — `app/Providers/AppServiceProvider.php:145`. So dev throws on lazy loads but **production silently allows them**; anything not exercised in dev/tests can regress unnoticed in prod.

Screens reviewed:

- **Dashboard** (`app/Livewire/Dashboard.php`) — mostly aggregate SUM/COUNT queries; `recentInvoices()` eager-loads `contact`; `topCustomers()` uses a grouped pluck then one `whereIn`. Two issues:
  - Line 63–64: `$this->chart($now, $currency)` is **called twice** (once for the series, once for `chartTotal`), running the identical invoice query twice per render.
  - Lines 278–284: low-stock counter loads **every tracked product into memory** and filters in PHP (`->withStock()->get()->filter(...)->count()`). Mitigated by `withStock` (no per-row SUM), but still O(products) rows per dashboard hit; fine at hundreds, a drag at tens of thousands.
- **Executive** (`app/Livewire/Reports/Executive.php`) — delegates to `Kpis`, `ComplianceCalendar`, `ContractWatch`, `SlaBoard`; all eager-load their relations (`Kpis.php:161,242`; `ComplianceCalendar.php:107,124,153`; `ContractWatch.php:148,185`). SLA figures are indexed COUNTs. Clean.
- **Deals board** (`app/Livewire/Deals/Index.php:73,104`) — one `->get()` with `->with('contact')`, grouped in memory. No N+1, but **unbounded**: every open deal loads on every render.
- **Leads board** (`app/Livewire/Leads/Index.php:248–268`) — activities/untouched/open lists all eager-loaded (`subject`, `user`, `contact`, `deal`, `assignee`); open-deal/lead pickers select only `id`+name columns. No N+1; `leadsQuery()->get()` is unbounded like Deals.
- **Recruitment board** (`app/Livewire/Recruitment/Index.php:112–124`) — vacancies `->with('position')`, applications `->with(['candidate','vacancy.position'])`, grouped by stage. Clean.
- **Workflow** (`app/Livewire/Workflow/Index.php:207–213`) — `->with('steps')`. Clean.
- No `->get()`/`::all()` model queries inside Blade loops; view-level `->get()` hits (`resources/views/partials/topbar.blade.php:3` etc.) are all `CurrentCompany::get()` — a memoised singleton, not a query per call. `admin/companies/show.blade.php:40` iterates a static `AdminResources::all()` array, and `business/edit.blade.php:136` a static enum — not queries.

Verdict: no classic N+1 found on the heavy screens. Risks are (a) duplicated chart query, (b) in-memory low-stock filter, (c) unbounded board `->get()`s that will degrade with data volume, (d) no lazy-load guard in production to catch future regressions.

## 3. Missing DB indexes

Compared the read models' where-clauses to migration indexes. **Coverage is unusually good** — every hot path checked has a matching composite index:

| Query | Index |
|---|---|
| Documents by type/status/issue_date (dashboard, KPIs) | `documents (company_id, type, status, issue_date)` + `(company_id, issue_date)` + `(company_id, contact_id)` — `2026_07_27_000006:84–86` |
| Payments by received_at | `payments (company_id, received_at)` — `2026_07_27_000007:30` |
| Service SLA sweep (`response_due_at`/`resolution_due_at` under status) | `service_tickets (company_id, status, response_due_at)` and `(…, resolution_due_at)` and `(…, status, priority)` — `2026_09_11_000702:95–98` |
| Compliance calendar (`next_due_on`, filings status) | `compliance_obligations (company_id, next_due_on)`; `compliance_filings (company_id, status)` — `2026_09_11_000201:81,125–126` |
| Contract watch (status / notice_by / ends_on) | `contracts (company_id, status)`, `(company_id, notice_by)`, `(company_id, ends_on)` — `2026_09_11_000101:94–96` |
| Leads board | `leads (company_id, status)` — `2026_09_12_000101:61` |
| Stalled workflows (`Kpis.php:264` `where status='stalled'`) | `workflow_instances (company_id, status)` — `2026_08_26_000001:91` |
| Risks | `risks (company_id, status)`, `(company_id, next_review_on)` — `2026_09_11_000201:184–185` |

Foreign keys throughout use `foreignId()->constrained()` (implicit index on MySQL). **No missing index found on any queried hot path.** (Not exhaustively verified across all 129 migrations, but every read-model clause traced resolves to an index.)

## 4. Queue / scheduler assumptions

- `.env.example:49` sets `QUEUE_CONNECTION=database` and lines 85–86 document that a worker (`php artisan queue:work --tries=3`) must run — good.
- **Sync-driver hazard — webhook retries.** `app/Jobs/DeliverWebhook.php:195`: `self::dispatch($delivery->id)->delay(now()->addSeconds($delay))`. The sync driver **ignores delays and runs dispatches inline**, so with `QUEUE_CONNECTION=sync` a dead endpoint executes its entire hand-rolled retry schedule (HTTP timeouts included) synchronously inside the originating web request (`WebhookDispatcher.php:157`, `Settings/Webhooks.php:172`, `Api/WebhookController.php:170`). Nothing is lost, but the user request blocks for the full timeout × attempts chain.
- ~26 `ShouldQueue` notifications (all of `app/Notifications/`) — under sync they send inline (slow requests when SMTP is slow, and a mail failure surfaces as a user-facing 500 instead of a failed job). Functional, but degraded.
- **Scheduler** (`routes/console.php`) covers: lease expiry, sync-receipt pruning, demo conversion, renewal reminders, low-stock alerts, notification digests/quiet-hours release, SLA sweep (15 min), action reminders, audit pruning, VIP expiry, recurring invoices, dunning. All critical recurring work found is registered. One critical dependency: **recurring invoices and SLA breach detection exist only via cron** — a deployment without the documented cron entry silently stops billing and SLA tracking.
- Handoff-declared missing scheduled jobs (not in `routes/console.php` because not built): compliance-deadline reminder emails, document-expiry alerts, e-signature reminders (`docs/handoff/4.2-integration.md` "Known gaps").

## 5. Error surfaces

`resources/views/errors/` contains **only `403.blade.php`**. There is no `404.blade.php`, `500.blade.php`, `503.blade.php`, or `419.blade.php` — those render Laravel's stock framework pages, unbranded, in production. For a white-label SaaS this is a visible gap on every mistyped URL and every expired session (419 on Livewire forms is common).

## 6. `.env.example` completeness vs `env()` calls

Custom keys read by config/app but **absent from `.env.example`**:

- **`CLAMAV_SOCKET`** — read at `app/Support/UploadGate.php:227`. Doubly serious: (a) not in the example, so nobody sets it and upload virus-scanning is a documented silent no-op (`UploadGate.php:179`); (b) it is a **raw `env()` call outside `config/`** — the only one in `app/` — so after `php artisan config:cache` (standard prod deploy, and `docs/DEPLOYMENT.md` runs it) `env()` returns `null` **even if the variable is set**, disabling scanning unconditionally.
- `CONTACT_RECIPIENT` (`config/opes.php:142`) — falls back to MAIL_FROM; contact-form mail goes to the wrong inbox silently if unset.
- `CSP_ENABLED` / `CSP_REPORT_ONLY` (`config/security.php:15`) — defaults on; undocumented kill-switch.
- `OPES_DEMO_LOGINS` — undocumented toggle.
- `ORANGE_MONEY_OAUTH_PATH` — has a sane default; the other six ORANGE_MONEY keys are present (`.env.example:114–119`).

Everything else in the diff (AWS_*, REDIS_*, MEMCACHED_*, SQS_*, BEANSTALKD_*, AUTH_*, DB_*, LOG_*, SESSION_*, POSTMARK/RESEND, SANCTUM_*) is stock Laravel config with safe defaults — acceptable to omit.

## 7. Storage / filesystems

`config/filesystems.php` is production-sane:

- `local` → `storage/app/private`, `serve => true`.
- Dedicated **`documents` disk** (`storage/app/documents`), private, no URL, controller+policy-gated — correct for signed contracts etc.
- `public` disk URL derives from `APP_URL` (trailing-slash trimmed).
- `links` maps `public/storage` → `storage/app/public`; `docs/DEPLOYMENT.md:134` runs `php artisan storage:link`. 

No misconfiguration found. Only note: both private disks set `'throw' => false`, so a failed write returns `false` rather than throwing — callers must check return values (not audited call-by-call here).

## 8. Handoff-declared open limits — master list

From `docs/handoff/` and `docs/HANDOVER.md`:

1. `2.16-bulk.md:59` — remaining bulk actions not built; `BulkActions::each()` is the ready loop.
2. `3.3-integration.md:123` — statement aging uses *today's* balance, not as-at-period balance (no balance history stored). Same trade-off in `Reports\Aging`.
3. `3.4-integration.md:174` — supplier-side mirror of #2: `SupplierAccount::openBills()` ages with today's `amount_paid`.
4. `4.2-integration.md` "Known gaps" — no scheduled compliance-deadline reminder (shared gap with document-expiry and e-signature reminders); no obligation↔risk link; filing-evidence retention inherited not configured.
5. `4.2-integration.md` "Still not built" — no evidence upload from the filing form; no close/reopen screen for risks (`RiskRegister::close()` has no caller); no obligation editing screen (cadence/`is_active` fixed at creation).
6. `4.7-integration.md:123` — **largest declared gap**: editing an SLA policy does not recompute deadlines on open tickets (`SlaClock::apply()` per ticket, ideally queued, is unbuilt).
7. `4.7-integration.md:340` — `/service/tickets` and `/service/jobs` routes not built; three views still link via `url()` instead of `route()` (`index/show/policies.blade.php`).
8. `audit-retention.md` "Unresolved" — pruned audit rows are deleted with no export/archive; legal hold covers documents only (payroll disputes have no hold object); `FINANCIAL_SUBJECTS` is a hand-kept string list that degrades silently on model rename; `company_id = null` system rows handling.
9. `docs/HANDOVER.md:256` points to `docs/GAP-ANALYSIS.md` as the built/part-built/not-built register for the whole ERP.

## 9. Skipped / incomplete tests

- `tests/Feature/BrandingPublicPagesTest.php:80` — conditional `markTestSkipped('this verification subject has no public page')` (data-dependent guard, benign).
- `tests/Feature/PaymentRefundTest.php:247` — conditional `markTestSkipped('Loyalty is not earning on this configuration.')` — a guard that could silently mask the loyalty-refund assertion forever if config drifts; worth confirming it actually runs in CI.
- No `markTestIncomplete`, no unconditional skips. (`PaymentSchedulingTest.php:257` is a domain method named `skip()`, not a test skip.)

---

## Top findings (severity order)

1. **CLAMAV_SOCKET read via raw `env()` outside config** (`app/Support/UploadGate.php:227`) — after `config:cache` virus scanning is off even when configured; also absent from `.env.example`, so it is a silent no-op by default.
2. **No branded 404/500/503/419 pages** — only `errors/403.blade.php` exists; production shows stock Laravel pages, and Livewire session expiry (419) is a routine user-visible event.
3. **SLA policy edits never recompute open-ticket deadlines** — self-declared largest gap (`docs/handoff/4.7-integration.md:123`); stale breach clocks misreport SLA compliance.
4. **Webhook retry chain runs inline under sync queue** (`DeliverWebhook.php:195`) — delays ignored, full timeout×attempts executed inside the web request.
5. **Recurring invoicing, dunning and SLA sweeps depend entirely on one cron entry** — a deploy that forgets it silently stops billing (`routes/console.php`).
6. **`preventLazyLoading` disabled in production** (`AppServiceProvider.php:145`) — intended, but combined with no query monitoring, future N+1 regressions ship silently.
7. **Kanban boards load all rows unbounded** (`Deals/Index.php:73`, `Leads/Index.php:248`) — no N+1, but render cost grows linearly with total open deals/leads.
8. **Dashboard runs the sales-chart query twice per render** (`Dashboard.php:63–64`) and filters low stock in PHP over all tracked products (`:278–284`).
9. **Audit pruning deletes with no archive/export**, and legal hold covers documents only (`docs/handoff/audit-retention.md`).
10. **`CONTACT_RECIPIENT`, `CSP_ENABLED`, `OPES_DEMO_LOGINS` missing from `.env.example`** — contact mail silently routes to MAIL_FROM if unset.
