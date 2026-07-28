# OPES360 — Production Readiness Assessment

**Assessed:** 28 July 2026 · 154 tests, 55 routes, 16 commits
**Verdict:** ready for a **supervised pilot**, not for open public launch.

This document is deliberately blunt about what would break first. Everything in
§3 is real work that has not been done, not polish.

---

## 1. What is built and working

Verified by the automated suite plus a browser sweep of every page.

| Area | State | Notes |
|---|---|---|
| Multi-company tenancy | **Solid** | Global scope applied via trait; fails closed; a test asserts every table with `company_id` has a scoped model |
| Roles & permissions | **Solid** | Seven roles, granular permissions, per-user overrides — enforced by policies, route middleware, per-action checks and conditional rendering; a lesser role is proven restricted by test |
| Registration & onboarding | **Solid** | Two-step signup, company creation, owner membership, identity token minted immediately |
| Authentication | **Solid** | Login throttled per email+IP, TOTP 2FA with encrypted secrets and single-use recovery codes, password reset that does not leak account existence |
| Business identity | **Solid** | Profile, encrypted tax IDs with blind index, logo upload, public profile, permanent business QR |
| Logo generator | **Good** | Parametric SVG, 8 palettes × 8 marks × 3 layouts, industry-tuned suggestions, canvas sizes to the name |
| Stationery | **Good** | A4/A3 letterhead, double-sided cards, email signature, stamp — all print-ready with inlined QR |
| Sales documents | **Solid** | Seven types, drafts, issuing with ledger-backed numbering, conversion, voiding, immutability enforced at model layer |
| Payments & receipts | **Solid** | Part-payments, allocation, numbered receipts, row-locked against double-spend, A4/A5/58mm/80mm print |
| Verification & QR | **Solid** | Public pages for documents, receipts, businesses, artisans; tamper detection; scan logging; built-in scanner |
| CRM & catalogue | **Good** | Four contact types with history and notes; products/services with append-only stock ledger |
| Artisans | **Good** | Public verified profiles, testimonials, vCards |
| Reports | **Good** | Revenue/collected/outstanding/average, trend chart, method breakdown, CSV export |
| Audit trail | **Good** | Attribute-level diffs on the models that matter, secrets redacted |
| Offline sync | **Foundational** | Idempotent push/pull API, IndexedDB store, ordered outbox — **but not yet wired into the write paths** (see §3.1) |
| PWA | **Good** | Installable, app-shell precache, offline page, live sync-status indicator |
| Design system | **Solid** | One token set drives light and dark; no page has a dead link or console error |

---

## 2. What is genuinely production-grade

These would survive contact with real users today.

**Tenant isolation.** The scope is applied through a trait rather than per model,
fails closed when no company is set, and is guarded by a test that walks every
table. The one place it is deliberately crossed — public verification and profile
pages — resolves *as* the subject's company and renders inside that scope.

**Financial integrity.** Issued documents are immutable at the model layer, so
the rule holds for sync writes and console commands, not just forms. Payments
re-read the document under a row lock inside the transaction, so two cashiers
cannot both pass the balance check on stale data. Overpayment is refused rather
than absorbed. Numbers come from an auditable lease ledger; voided numbers are
retired, never reused.

**Verification.** One immutable token per subject, tamper detection by content
hash, and a verdict page that distinguishes *voided* from *invalid* from
*tampered* — distinctions that matter to whoever is holding the paper.

**Authorisation.** Enforced at four layers — `can:` middleware on routes,
policies on every scoped model, an explicit `$this->authorize(...)` at the top of
every mutating Livewire action (so a crafted component call cannot bypass the
route gate), and conditional rendering so the UI never offers a link or button
that answers 403. Gates are generated from the same catalogue the seeder writes,
with a test asserting no permission exists without a gate — an ability with no
gate is silently denied to everyone but the Owner, which looks like it works.
Every authorisation test drives a *non-owner*, since the Owner short-circuits.

**Auth.** Throttling is per email+IP (either alone is bypassable), 2FA secrets
are encrypted, enrolment only counts once confirmed, and the enrolment QR is
rebuilt server-side rather than from a request parameter.

---

## 3. What would break first — blocking items

### 3.1 The offline engine is not connected to the UI — **highest risk**

The sync protocol is built and tested (idempotency, conflict rules, cursors,
device revocation), and the client store and outbox exist. But the app's forms
still write directly through Livewire to the server. Nothing currently calls
`opesSync.record(...)`, so **creating an invoice with no connection still fails.**

This is now the largest gap between what the product does and what the
specification promises, because "offline-first" is a headline claim.

What remains: route each write path through the outbox, read lists from IndexedDB
when offline, and implement device-side number leasing so an offline receipt gets
a final number. The design for all of it is in
`docs/architecture/offline-sync.md`; roughly a two-to-three week piece.

### 3.2 No email delivery is configured

Password reset and email verification are implemented and tested with a fake
mailer. `MAIL_MAILER` still points at `log`. Reset links currently go to
`storage/logs/laravel.log`, not to users. Needs a real transport (SES, Postmark,
Resend) plus SPF/DKIM before anyone outside the pilot can recover an account.

### 3.3 Untested against MySQL

Every migration and query is written for MySQL 8.4 and nothing MySQL-specific was
avoided, but **the suite has only ever run on SQLite.** Two known risk areas:
strict mode on zero-dates, and the `orderByRaw`/`selectRaw` fragments in the
sales list and reports. Running the suite against MySQL is a half-day and must
happen before any deploy.

### 3.4 No production infrastructure

None of this exists yet: queue worker (Horizon) despite Redis being assumed,
scheduler, backup automation and a rehearsed restore, error tracking, uptime
monitoring, log aggregation, CI pipeline, deployment process.

`docs/PLAN.md` Phase 13 covers this; it has not been started.

---

## 4. Known limitations worth stating plainly

**No Content-Security-Policy.** Baseline headers are set, but a real CSP needs
nonce-based handling of Livewire's inline bootstrap and the inline SVG in print
views. A permissive `unsafe-inline` policy would have looked like protection
while providing none, so none was shipped.

**Thermal printing is Android-only.** Web Bluetooth and WebUSB do not exist in
iOS Safari. Receipts render and print through the browser dialog everywhere;
driving a 58/80mm printer directly works only on Android. The iOS path
(render-to-image plus the vendor app's share sheet) is documented but not built.

**No AR layer.** Every token is AR-ready by design, but nothing renders AR. Per
`docs/architecture/qr-ar.md` this needs a feasibility spike before scope is
committed.

**AI assistant not built.** The logo generator's suggestion engine is
deterministic, not a model. Modules 12 and 13 (AI copy, document generator) are
untouched.

**Sequence gaps and tax law.** Number leasing produces auditable gaps. This has
not been checked against any specific jurisdiction, and it is a genuine go/no-go
question for invoicing compliance in some countries.

**Single-currency per company.** The schema carries currency and exchange rate
per document, but there is no FX handling or multi-currency reporting.

---

## 5. Recommended path to launch

| Step | Work | Rough size |
|---|---|---|
| ~~0~~ | ~~**Enforce permissions**~~ — *done: policies, route middleware, per-action checks, UI gating* | — |
| 1 | **Run the suite against MySQL 8.4** and fix what surfaces | 0.5 day |
| 2 | **Configure mail** and verify reset/verification end to end | 1 day |
| 3 | **Infrastructure** — queue worker, scheduler, backups + restore rehearsal, error tracking, CI | 1 sprint |
| 4 | → **Supervised pilot** with 5–10 friendly businesses | — |
| 5 | **Wire the offline engine** into the write paths, including device number leasing | 2–3 weeks |
| 6 | **Security review / pen test**, add a real CSP | 1 week |
| 7 | → **Open launch** | — |

Steps 1–3 are the gate to letting anyone outside the room use it. Step 5 is the
gate to honestly calling the product offline-first in marketing.

---

## 6. Test coverage summary

154 tests, 596 assertions. What they actually protect:

- **Tenancy** — cross-company reads, fail-closed behaviour, the model/table pairing
- **Financial correctness** — immutability, tamper detection, overpayment refusal,
  numbering sequences and block rollover, void guards
- **Sync** — idempotent replay, per-envelope isolation, server-owned column
  stripping, conflict detection, cursor pagination, device revocation
- **Auth** — throttling, 2FA including recovery codes and unconfirmed enrolment,
  reset without account enumeration, encrypted secrets at rest
- **Every page renders** for a real user against the demo dataset, and every page
  redirects guests
- **Navigation integrity** — every route name referenced by config exists
- **Authorisation** — a Cashier refused Reports and the branding studio, a Sales
  Officer allowed to draft but refused to issue, an Accountant refused a void, a
  read-only user refused a payment, a non-member refused everything, fail-closed
  when no company is current, the Owner never locked out, and a check that every
  seeded permission has a matching gate so the two cannot drift

**Not covered:** load and performance, browser compatibility beyond Chromium,
and an accessibility audit.
