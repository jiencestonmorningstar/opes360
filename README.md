# OPES360

**Business Identity & Operations Suite**
Developed by [Opesware Technologies](https://opesware.com)

A mobile-first, offline-first Progressive Web App that helps businesses establish a professional brand,
generate trusted documents, and run daily operations from any device — with or without a connection.

**Stack:** Laravel 12 · PHP 8.4 · MySQL 8.4 LTS · Livewire 3 · Alpine.js · Tailwind CSS · Service Worker +
IndexedDB

---

## Status

Planning. No application code yet — this repository currently holds the build plan and architecture
decisions.

## Documentation

| Document | What it covers |
|---|---|
| [`docs/PLAN.md`](docs/PLAN.md) | **Start here.** 13 phases across 4 releases, dependencies, risks, open questions |
| [`docs/architecture/decisions.md`](docs/architecture/decisions.md) | Key technical decisions and their trade-offs |
| [`docs/architecture/data-model.md`](docs/architecture/data-model.md) | Core schema, phase by phase |
| [`docs/architecture/offline-sync.md`](docs/architecture/offline-sync.md) | Sync protocol, conflict rules, offline document numbering |
| [`docs/architecture/qr-ar.md`](docs/architecture/qr-ar.md) | Verification tokens, QR generation, AR approach |
| [`docs/flows/`](docs/flows/) | User flows — being added |

## Release milestones

- **R1 — Alpha (Phases 1–3):** business identity, logo and brand kit, printable stationery. Sellable on its own.
- **R2 — Beta (Phases 4–7):** offline engine, CRM and catalogue, sales documents, payments and receipts. The core product.
- **R3 — GA (Phases 8–13):** AR layer, artisan profiles, dashboard and reports, AI assistant, document generator, hardening.
- **R4 — Post-GA (Phase 14):** business directory and marketplace.
