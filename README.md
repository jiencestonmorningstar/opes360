# OPES360

**Business Identity & Operations Suite**
Developed by [Opesware Technologies](https://opesware.com)

A mobile-first, offline-first Progressive Web App that helps businesses establish a professional brand,
generate trusted documents, and run daily operations from any device — with or without a connection.

**Stack:** Laravel 12 · PHP 8.4 · MySQL 8.4 LTS · Livewire 3 · Alpine.js · Tailwind CSS 4 · Service Worker +
IndexedDB

---

## Status

**Working application.** Every module in the navigation is built and tested; the
core commercial loop runs end to end on a phone. See [`docs/PLAN.md`](docs/PLAN.md)
for the roadmap and what remains.

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
- **Platform** — multi-company tenancy, seven roles, registration and onboarding,
  PWA shell with offline page, light and dark themes

**Not yet built:** the full offline engine (IndexedDB mirror, outbox, sync protocol —
Phase 4), AI logo generation (Phase 2), the AR layer (Phase 8), and email
verification / password reset / 2FA.

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

**Design tokens.** Colours are defined once in `resources/css/app.css` and re-pointed under `.dark`, so
components need a `dark:` variant only where the *treatment* differs between themes, not merely the colour.

## Documentation

| Document | What it covers |
|---|---|
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
