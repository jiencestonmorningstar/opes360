# OPES360

**Business Identity & Operations Suite**
Developed by [Opesware Technologies](https://opesware.com)

A mobile-first, offline-first Progressive Web App that helps businesses establish a professional brand,
generate trusted documents, and run daily operations from any device — with or without a connection.

**Stack:** Laravel 12 · PHP 8.4 · MySQL 8.4 LTS · Livewire 3 · Alpine.js · Tailwind CSS 4 · Service Worker +
IndexedDB

---

## Status

**Phase 0 in progress** — foundation and the dashboard shell are built. See [`docs/PLAN.md`](docs/PLAN.md) for
the full roadmap.

Built so far:

- Multi-company tenancy with row-level isolation, enforced by a global scope applied through a trait rather
  than per model
- Seven seeded roles with granular permissions and per-user overrides
- Schema for identity, verification tokens, CRM, catalogue, sales documents, payments and receipts
- Immutable issued documents with content hashing for tamper detection
- Responsive dashboard matching the product design in light and dark themes
- PWA manifest and installable app shell

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
