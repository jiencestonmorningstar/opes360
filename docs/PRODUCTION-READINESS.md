# OPES360 — Production Readiness Assessment

**Assessed:** 28 July 2026 · 200 tests, 61 routes, 21 commits
**Verdict:** ready for a **supervised pilot**. The launch blockers are closed;
what remains before an open launch is operational, not structural.

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
| Offline sync | **Solid** | Wired end to end: invoices, contacts and items compose and save with no connection, device number leasing so an offline invoice keeps the number printed on the customer's copy, verified in a real browser with the network disabled |
| Document generator | **Good** | Eight templates on the company letterhead, frozen and verifiable at issue, with a review notice printed into anything that creates an obligation |
| Security headers | **Good** | Nonce-based CSP enforced, zero violations across every page (see §4 for the one directive that cannot be tightened) |
| Deployment tooling | **Good** | `opes:doctor` pre-flight check, CI on SQLite *and* MySQL 8.4, scheduled lease expiry and receipt pruning, DEPLOYMENT.md |
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

### 3.1 No mail credentials — **highest remaining risk**

The two account emails are implemented, branded, queued and tested, and
`opes:doctor` refuses to pass an environment without a transport. But
`MAIL_MAILER` is still `log` because there are no credentials to set. Until a
real transport (SES, Postmark, Resend) is configured with SPF and DKIM on the
sending domain, **a locked-out user cannot recover their account** — reset links
go to `storage/logs/laravel.log`.

This is configuration, not code. About a day including DNS propagation, and
`php artisan opes:doctor --mail=you@yourdomain.com` confirms it end to end.

### 3.2 Payments cannot yet be taken offline

Invoices, contacts and items all compose and save with no connection. Recording a
*payment* offline does not, because a receipt needs its own leased number and the
allocation logic has to survive being replayed out of order against an invoice
the device may not have seen paid.

The lease machinery already supports receipt blocks, so this is the smaller
remaining half of the offline work — roughly a week — but a market trader taking
cash with no signal still cannot issue a receipt on the spot.

### 3.3 No error tracking or monitoring

A 500 in production is visible only in `storage/logs`. Sentry (or equivalent),
uptime monitoring and log aggregation are all unconfigured. Nothing here is hard;
all of it needs a host that does not exist yet.

### 3.4 Backups are documented, not automated

`docs/DEPLOYMENT.md` §5 states exactly what must be backed up and — more
importantly — that the restore must be *rehearsed* before launch. Neither is
automated, because both depend on the hosting choice.

---

## 4. Known limitations worth stating plainly

**The CSP still needs `unsafe-eval`.** Everything else is locked down —
script/connect/form-action/base-uri/frame-ancestors/object-src are all restricted
to this origin, and inline scripts carry a per-request nonce. But Alpine
evaluates the expressions inside `x-data` and `x-show` with `new Function()`, and
Livewire 3 ships Alpine, so that one directive cannot be dropped without
rewriting every interactive view against the CSP-safe Alpine build. Inline
*styles* also stay allowed, for the iOS safe-area insets and print geometry.
Both are stated in `App\Support\Csp` rather than left to be discovered.

**Thermal printing is Android-only.** Web Bluetooth and WebUSB do not exist in
iOS Safari. Receipts render and print through the browser dialog everywhere;
driving a 58/80mm printer directly works only on Android. The iOS path
(render-to-image plus the vendor app's share sheet) is documented but not built.

**No AR layer.** Every token is AR-ready by design, but nothing renders AR. Per
`docs/architecture/qr-ar.md` this needs a feasibility spike before scope is
committed.

**AI assistant not built (Module 12).** The logo generator's suggestion engine
and the document templates are both deterministic, not model-driven. Nothing in
the product calls a language model. The document generator (Module 13) is built
and works, but its templates are fixed text with merge fields — there is no
AI-assisted drafting or rewriting.

**Sequence gaps and tax law.** Number leasing produces auditable gaps. This has
not been checked against any specific jurisdiction, and it is a genuine go/no-go
question for invoicing compliance in some countries.

**Single-currency per company.** The schema carries currency and exchange rate
per document, but there is no FX handling or multi-currency reporting.

**Editing offline is deliberately not supported.** New records can be created
with no connection; editing an existing one cannot. Two devices editing the same
customer while both offline is a merge problem this product does not need to
solve today, and last-writer-wins on someone's bank details is a bad answer.

**Contract templates are drafting aids, not legal advice.** They are generic and
have not been reviewed against any jurisdiction. Every template that creates an
obligation prints that notice into the document itself.

---

## 5. Recommended path to launch

| Step | Work | Rough size |
|---|---|---|
| ~~—~~ | ~~Enforce permissions~~ · ~~Run against MySQL~~ · ~~Wire the offline engine~~ · ~~Ship a CSP~~ · ~~CI~~ — *done* | — |
| 1 | **Configure mail** and confirm with `opes:doctor --mail=…` | 1 day |
| 2 | **Provision the host** — queue worker, cron, error tracking, backups + a rehearsed restore | 3–4 days |
| 3 | → **Supervised pilot** with 5–10 friendly businesses | — |
| 4 | **Offline payments and receipts** — the remaining half of the offline work | ~1 week |
| 5 | **Security review / pen test** | 1 week |
| 6 | → **Open launch** | — |

Steps 1–2 are the gate to letting anyone outside the room use it. Step 4 is the
gate to calling the product fully offline-first without qualification.

---

## 6. Test coverage summary

200 tests, 754 assertions, run against **both SQLite and MySQL 8.4 in strict
mode**. What they actually protect:

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
- **Offline numbering** — a leased number honoured, one outside the lease refused
  rather than renumbered, two devices never sharing a number, the server pool
  never walking into a device's block, out-of-order claims never rewinding, and
  an abandoned lease closed with its gap recorded
- **Document generation** — no template leaves an unfilled placeholder, optional
  clauses vanish cleanly, user text cannot smuggle markup onto the page, an
  issued document is frozen and tamper-evident
- **Content Security Policy** — the directives that stop an injected script, a
  per-request nonce that is never reused, and no un-nonced inline script on the
  page

**Not covered:** load and performance, browser compatibility beyond Chromium,
and an accessibility audit.
