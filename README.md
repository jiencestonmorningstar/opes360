# OPES360

**Business Identity & Operations Suite**
Developed by [Opesware Technologies](https://opesware.com)

A mobile-first, offline-first Progressive Web App that helps businesses establish a professional brand,
generate trusted documents, and run daily operations from any device — with or without a connection.

**Stack:** Laravel 12 · PHP 8.4 · MySQL 8.4 LTS · Livewire 3 · Alpine.js · Tailwind CSS 4 · Service Worker +
IndexedDB

---

## Status

**Working application, ready for a supervised pilot.** Every module in the
navigation is built and tested, the core commercial loop runs end to end on a
phone, and it does so with the network switched off. See
[`docs/PRODUCTION-READINESS.md`](docs/PRODUCTION-READINESS.md) for a blunt
assessment of what would break first, and [`docs/PLAN.md`](docs/PLAN.md) for the
roadmap.

203 tests, run against both SQLite and MySQL 8.4 in strict mode.

**Built:**

- **Identity** — company profile with encrypted tax identifiers, logo upload, public
  business page, permanent business QR
- **Stationery** — A4/A3 letterhead, double-sided business cards, email signature,
  company stamp, all print-ready
- **Sales** — seven document types, drafts, issuing with ledger-backed numbering,
  quotation→invoice conversion, voiding
- **Payments & receipts** — part-payments, allocation, numbered receipts, A4/A5 and
  58/80mm thermal print
- **Verification** — public `/v/{token}` pages for documents, receipts, businesses and
  artisans, with QR generation, tamper detection and a built-in scanner
- **CRM & catalogue** — contacts of four types with history and notes, products and
  services with an append-only stock ledger
- **Artisans** — public verified profiles with skills, testimonials and vCards
- **Operations** — dashboard, reports with CSV export, payments ledger, due-date
  calendar, settings, help
- **Documents** — eight business-document templates (agreements, letters,
  certificates, minutes) on the company letterhead, frozen and verifiable at issue
- **Offline** — invoices, contacts, items and payments all compose and save with
  no connection, with device number leasing so an offline invoice or receipt keeps
  the number printed on the customer's copy
- **Platform** — multi-company tenancy, seven roles enforced end to end, 2FA,
  registration and onboarding, installable PWA, light and dark themes

**Not built:** the AR layer (Phase 8 — every token is AR-ready by design, but
nothing renders AR), and the AI assistant (Phase 11 — nothing in the product
calls a language model; the logo suggestions and document templates are
deterministic). Editing an existing record offline is deliberately unsupported;
see the readiness document for why.

## Getting started

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
php artisan serve
```

The seeder creates a demo business with the dataset shown in the design.
Sign in with **john@opesware.com** / **password**.

MySQL 8.4 is the production target. For local development the `.env` default of SQLite works; the migrations
are written to run on both.

### Tests

```bash
php artisan test        # feature + unit suite
vendor/bin/pint         # code style
```

CI runs the suite twice — SQLite for fast feedback, MySQL 8.4 for the answer
that counts — plus a migrate-and-seed from scratch, since migrations and seeders
are shipped artefacts too.

### Deploying

```bash
./scripts/build-release.sh                              # upload-ready archive
php artisan opes:doctor --mail=you@yourdomain.com       # pre-flight check
```

Checks the things that look fine in a browser and are discovered at the worst
possible moment: mail still pointed at the log file, a queue with no worker so
reset links are never sent, debug mode left on, a missing storage link. It exits
non-zero on anything blocking. Full instructions in
[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md), or
[`docs/DEPLOY-NAMECHEAP.md`](docs/DEPLOY-NAMECHEAP.md) for cPanel shared hosting.

## Architecture at a glance

**Tenancy.** Every business table carries `company_id`. Models pick it up through `BelongsToCompany`, which
applies the scope on read and stamps it on write. A test asserts that any table with a `company_id` column has
a model using that trait, so a new model cannot ship unscoped by accident.

**Issued documents are immutable.** Once a document leaves draft, its content is frozen — corrections happen
by voiding and reissuing, or via credit notes. This is enforced in the model layer so it holds for sync writes
and console commands too. It also removes most sync conflicts by construction and makes tamper detection a
single hash comparison.

**Stock is an append-only ledger.** `stock_movements` holds signed quantities; there is no mutable quantity
column. Two devices selling the same item offline each append `-1` and both are correct.

**One verification token, many renderers.** Every verifiable subject — company, artisan, document, receipt —
gets a single immutable token. QR resolves it today and AR will resolve the same token in Phase 8, so the two
can never disagree about whether something is valid.

**Public pages cross the tenant boundary narrowly.** Verification and profile pages have no session, so they
resolve *as* the subject's company via `CurrentCompany::as()` rather than dropping the scope. They render
inside that closure — returning a View would defer rendering past the scope, and the scope fails closed.

**Numbers are leased, not assigned.** A document issued offline is printed and
handed over immediately, so it needs its final number *then* — a provisional one
the server later replaces would leave the customer holding paper that refers to
nothing. Devices lease a block while they still have signal; the server honours a
number only if it falls inside a lease that device holds, and refuses anything
else rather than renumbering. Every gap in the sequence has a dated row
explaining it.

**Forms own their state.** The document composer and the record-payment panel
hold their state in Alpine and submit once, rather than round-tripping per
keystroke. That is what makes them work at zero bars, and it is a real
improvement at two bars as well — which is this product's actual market.

**Design tokens.** Colours are defined once in `resources/css/app.css` and re-pointed under `.dark`, so
components need a `dark:` variant only where the *treatment* differs between themes, not merely the colour.

## Documentation

| Document | What it covers |
|---|---|
| [`docs/PRODUCTION-READINESS.md`](docs/PRODUCTION-READINESS.md) | What is production-grade, what would break first, and the path to launch |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | What must exist before the first deploy, and what to verify after it |
| [`docs/DEPLOY-NAMECHEAP.md`](docs/DEPLOY-NAMECHEAP.md) | cPanel shared hosting, step by step — cron-driven queue, document root, SMTP |
| [`docs/PLAN.md`](docs/PLAN.md) | 13 phases across 4 releases, dependencies, risks, open questions |
| [`docs/architecture/decisions.md`](docs/architecture/decisions.md) | Key technical decisions and their trade-offs |
| [`docs/architecture/data-model.md`](docs/architecture/data-model.md) | Core schema, phase by phase |
| [`docs/architecture/offline-sync.md`](docs/architecture/offline-sync.md) | Sync protocol, conflict rules, offline document numbering |
| [`docs/architecture/qr-ar.md`](docs/architecture/qr-ar.md) | Verification tokens, QR generation, AR approach |
| [`docs/flows/`](docs/flows/) | User flows |

## Release milestones

- **R1 — Alpha (Phases 1–3):** business identity, logo and brand kit, printable stationery.
- **R2 — Beta (Phases 4–7):** offline engine, CRM and catalogue, sales documents, payments and receipts.
- **R3 — GA (Phases 8–13):** AR layer, artisan profiles, dashboard and reports, AI assistant, hardening.
- **R4 — Post-GA (Phase 14):** business directory and marketplace.
