# OPES360 — Production Readiness Assessment

**Assessed:** 2 August 2026 · 932 tests, 163 routes, 138 commits
**Verdict:** ready for a **supervised pilot**. Every launch blocker that is code
has been closed. What remains is three pieces of configuration that need a host
and credentials this repository does not have, and one browser sweep that needs
a machine able to install Firefox and WebKit.

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
| Offline sync | **Solid** | Wired end to end: invoices, contacts, items *and payments* compose and save with no connection, with device number leasing so an offline invoice or receipt keeps the number printed on the customer's copy. Verified in a real browser with the network disabled |
| Document generator | **Good** | Eight templates on the company letterhead, frozen and verifiable at issue, with a review notice printed into anything that creates an obligation |
| Security headers | **Good** | Nonce-based CSP enforced, zero violations across every page (see §4 for the one directive that cannot be tightened) |
| Deployment tooling | **Good** | `opes:doctor` pre-flight check, CI on SQLite *and* MySQL 8.4, scheduled lease expiry and receipt pruning, DEPLOYMENT.md |
| PWA | **Good** | Installable, app-shell precache, offline page, live sync-status indicator |
| Design system | **Solid** | One token set drives light and dark; no page has a dead link or console error |
| SYSCOHADA books | **Solid** | Double-entry ledger fed by issuing, settling, spending and payroll; trial balance, grand livre, income statement, balance sheet. Voiding an invoice now extournes its entry rather than leaving revenue posted for a cancelled sale |
| Stock in the books | **Solid** | Issuing a document takes the goods off the shelf and voiding puts them back — neither happened before. Stocktakes value what is left at weighted average cost, carry it to 31 and post the movement to 6031, so achats less variation is the real cost of goods |
| Credit notes | **Solid** | Issuable in full or in part against an invoice, posted as the mirror of the sale including the TVA, goods returned to stock, guarded against crediting past the invoice total |
| Purchases & expenses | **Solid** | Supplier bills and direct spending with recoverable TVA, part-settlement, void with reversal |
| Fixed assets | **Good** | Capitalisation by category, straight-line depreciation posted monthly, disposal through 812/822 so the gain or loss falls out rather than being worked out |
| Bank reconciliation | **Good** | Statement import, matching against the ledger, opening balance so a business with history can start reconciling this month rather than from its first ever entry |
| Multiple stock locations | **Good** | Per-location quantities from the same append-only ledger, transfers that move stock without changing how much there is |
| HR & payroll | **Solid** | Employees separate from users, contracts that make June's payslip still say June's salary, CNPS/IRPP/CAC/CFC/FNE/TDL/RAV computed from `config/payroll.php` with each run keeping its own copy of the rates |
| Tax declarations | **Good** | TVA and the levies on wages worked out from the books, with every TVA entry listed so a figure can be traced. A worksheet, and it says so before it shows a number |
| Team & invitations | **Solid** | Invite by email, accept sets a password, roles changed and members removed — with the two irreversible mistakes (demoting yourself, removing the owner) refused outright |
| Modules | **Solid** | Sixteen modules switchable per business, enforced in one `Gate::before` so the navigation, routes and `@can` blocks cannot drift |

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
than absorbed — including when it arrives from a device that was offline while
someone else settled the invoice, where it surfaces as a conflict for a person
to resolve. Numbers come from an auditable lease ledger; voided numbers are
retired, never reused, and abandoned leases are closed hourly with their unused
range recorded, so every gap in the sequence has a dated explanation.

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

### 3.2 No error tracking or monitoring

A 500 in production is visible only in `storage/logs`. Sentry (or equivalent),
uptime monitoring and log aggregation are all unconfigured. Nothing here is hard;
all of it needs a host that does not exist yet.

### 3.3 Backups are documented, not automated

`docs/DEPLOYMENT.md` §5 states exactly what must be backed up and — more
importantly — that the restore must be *rehearsed* before launch. Neither is
automated, because both depend on the hosting choice.

### 3.4 Firefox and Safari have not been run

Not a code risk in the way the three above are, and not dismissible either. The
static evidence says the stylesheet degrades rather than breaks (§6, Browsers),
but nobody has watched Gecko or WebKit paint this. One run of
`scripts/audit/engines.mjs` on a machine that can install them closes it, and it
should happen before the pilot rather than after.

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
| ~~—~~ | ~~Enforce permissions~~ · ~~Run against MySQL~~ · ~~Wire the offline engine~~ · ~~Ship a CSP~~ · ~~CI~~ · ~~The books~~ · ~~Payroll~~ · ~~Performance~~ · ~~Accessibility~~ — *done* | — |
| 1 | **Configure mail** and confirm with `opes:doctor --mail=…` | 1 day |
| 2 | **Run the engine sweep** in Firefox and WebKit — `npx playwright install firefox webkit && node scripts/audit/engines.mjs` | 1 hour, plus whatever it finds |
| 3 | **Provision the host** — queue worker, cron, error tracking, backups + a rehearsed restore | 3–4 days |
| 4 | → **Supervised pilot** with 5–10 friendly businesses | — |
| 5 | **Security review / pen test** | 1 week |
| 6 | → **Open launch** | — |

Steps 1–3 are the gate to letting anyone outside the room use it. Nothing else
on this list blocks a pilot.

### What is still open in the code

Nothing known. The three items listed here in the previous revision — the demo
business charging no TVA while claiming to be registered, two CSP refusals on
every `wire:navigate`, and no way to record a delivery arriving — are all
closed (decisions 19 and 20).

---

## 6. Test coverage summary

203 tests, 770 assertions, run against **both SQLite and MySQL 8.4 in strict
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
- **Offline payments** — an invoice actually settles rather than the row merely
  landing, a replayed envelope not charging twice, and an invoice someone else
  settled first reported as a conflict instead of forced into a negative balance
- **Document generation** — no template leaves an unfilled placeholder, optional
  clauses vanish cleanly, user text cannot smuggle markup onto the page, an
  issued document is frozen and tamper-evident
- **Content Security Policy** — the directives that stop an injected script, a
  per-request nonce that is never reused, and no un-nonced inline script on the
  page

### Load and performance

`tests/Feature/QueryBudgetTest.php` holds a query ceiling per screen **and** the
rule that actually catches an N+1: ten times the rows must cost the same number
of queries. Two were live when it was written. Every ability check re-read the
user's membership, role and overrides, so the dashboard ran **137 queries** to
draw itself and every other screen about **88**; memoising the lookup for the
request took those to 29 and 10–19. And every product in a list ran its own
`SUM` over the stock ledger. Both are pinned — removing the aggregate makes the
test fail with *"went from 13 queries on a handful of rows to 47 on ten times as
many"*, which was verified by doing it.

Over HTTP, each screen is 6 requests and 84–247 KB with a time-to-first-byte of
51–81 ms on a cold PHP process. `route:cache` and `view:cache` both work and
neither made a measurable difference, so the deploy guide still recommends
`config:cache` alone — which it needs for correctness after an `.env` edit, not
for speed.

### Browsers

`scripts/audit/engines.mjs` sweeps every page in Chromium, Firefox and WebKit:
console errors, sideways scroll, thirteen feature probes asked of the engine
itself, and the geometry of the layout anchors compared across engines.

**Chromium 141 is clean across 27 pages. Firefox and WebKit have not been run** —
Playwright's browser CDN is blocked by this build environment's network policy
and no distribution Firefox is installable there either. The static analysis in
`docs/BROWSER-SUPPORT.md` is real evidence about what ships — the palette is hex
so nothing paints with `oklch()`, all 114 `color-mix()` uses sit inside
`@supports` guards, `backdrop-filter` carries its prefix — and it is not the same
as watching those two engines paint it. Treat them as unverified until the sweep
has been run somewhere it can install them; it is four commands.

### Accessibility

`scripts/audit/a11y.mjs` runs axe-core over every page in **both colour schemes**,
measures target size against WCAG 2.5.8, and walks the keyboard. All three pass:
zero axe violations, zero targets under the criterion, zero elements that take
focus without showing it, zero backward jumps in focus order.

Four real defects were found and fixed getting there: ten icon buttons whose
label was `display: none` below 420px and so had no accessible name at all;
eleven filter rows claiming `role="tablist"` with no tab panel anywhere; a count
badge whose `bg-white/20` over the brand fill fell under 4.5:1; and three
scrolling tables on Accounting with no way in from the keyboard.

Note that `scripts/audit/ui-audit.mjs` measures targets against 44×44 — WCAG
2.5.5, an **AAA** criterion this product does not claim — and so reports ~526
"failures" that are almost all inline links in a paragraph, which 2.5.8
explicitly exempts. Read `a11y.mjs` for the AA verdict.
