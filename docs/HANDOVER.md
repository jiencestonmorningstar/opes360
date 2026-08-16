# Opes360 — handover

**Date:** 2026-08-15
**Branch merged to:** `main` (fast-forward, no conflicts)
**Suite:** 1588 tests, 9195 assertions, all passing
**Migrations:** all applied; `opes360-install.sql` regenerated and current

---

## 1. The push is done

*Resolved 2026-08-16. Kept for the next person who hits the same wall.*

Every push from the session that wrote this document failed or hung:

```
remote: Permission to jiencestonmorningstar/opes360.git denied to exerateanalytical.
```

Windows Credential Manager cycles between at least three GitHub identities —
`exerateanalytical`, `opeshealthsystems`, and the correct one. Reads always
work (`git ls-remote` succeeds instantly); only writes fail. When the wrong
identity is cached it 403s; when Credential Manager decides to prompt it opens a
**GUI dialog**, which is why some pushes hung indefinitely rather than erroring.
If it happens again, clear the cache and push:

```bash
cmdkey /delete:git:https://github.com
```

Both branches are now on the remote and nothing is outstanding.

---

## 2. State of the repository

| | |
|---|---|
| `main` | `05882a3` — everything merged, fast-forward only, pushed |
| `claude/api-first-crm-and-imports` | `0d13f83`, pushed — the working branch |
| `claude/opes360-phase-planning-min3mv` | the repo's default branch on GitHub, now behind |

Working tree is clean. Nothing is half-applied.

---

## 3. What was built this session

### VIP membership — review fixes

An adversarial review found the discount was applied by the API controller but
**not by the invoice screen**, which is how most invoices are actually raised. A
member who had paid for the benefit was charged full price at the counter.
Fixed, plus a row lock on `sell()` and a month-end overflow (a 1-month term sold
31 January ran to 2 March).

### Branding module

The largest piece. An owner picks a seed colour; the platform derives every
colour that carries text so nothing they choose can make the product unreadable.

- `app/Support/Colour.php` — sRGB ↔ OKLCH, WCAG contrast, gamut mapping by
  chroma reduction (clipping channels dragged hue 4.9°)
- `app/Support/BrandPalette.php` — one seed → brand, secondary, six accent
  families × ink/fill/tint × light/dark
- Screen at `/business/branding`, API at `/api/v1/branding`
- Liquid-glass skin on chrome only, three guards (`@supports`,
  `prefers-reduced-transparency`, `@media print`)
- The navigation rail wears the brand colour, from the **fill** role — the value
  guaranteed to carry white text
- `docs/branding.md`

**The hostile-seed corpus is the real guarantee**: 13 seeds (Spotify green,
pure yellow, pure white, mid grey…) × every role × both themes, 2797 assertions.
If one fails the generator is wrong — never loosen the assertion.

### ERP gaps — 3 of 8 done

- **AR/AP aging** — 30-day buckets both sides, drill-down, CSV
- **Recurring invoices** — nightly at 00:45, catch-up capped at 12/run
- **Dunning** — reminders at 7/30/60 days, each rung sent once
- **Procurement cycle** — purchase order → goods receipt → three-way match

### Documents module — core underway

Audit first (`docs/superpowers/specs/2026-08-15-documents-module-audit.md`),
which found two blockers before any code was written.

Built: classification metadata, folders, ERP relationships, uploads with a
private disk, the Library panel on the customer profile.

---

## 4. Things that will bite whoever continues

### The `documents` table is the ERP's

`documents` holds invoices, quotations, proformas, receipts, purchase orders —
67 live rows behind a 236-line model. **The Documents module uses
`business_documents` and `business_document_*`.** The original brief proposed
creating a `documents` table; that would collide with the most load-bearing
table in the product.

### Tests run on SQLite; production is MySQL

This hid a real bug. A unique key across four `varchar(255)` columns summed to
**3164 bytes**, over MySQL's 3072 limit — the table could not be created at all.
SQLite accepted it silently, so **the suite was green while a deploy would have
failed outright.**

`php artisan opes:export-schema` builds a real MySQL database and is currently
the *only* thing guarding this. **Run it before every commit that adds a
migration.** It needs `mysqldump` on PATH:

```bash
export PATH="/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"
```

### Everything else uploaded is still on the public disk

Documents now use a private `documents` disk with no URL. **The `media` table's
other consumers, and every logo and avatar, are still on `public`** — a
guessable URL serves them to anybody. Fine for a logo; worth auditing before
anything sensitive goes near it.

### Issued documents are immutable, but filable

`BusinessDocument` throws on any edit to an issued document. Filing columns
(`kind`, `tags`, `folder_id`, `owner_id`, `security`, `expires_on`) are on an
allow-list because organising is not editing. **None of them appear in
`canonicalPayload()`**, so the tamper hash survives filing — there is a test
that fails if one ever leaks into that payload. Do not add a filing column to
that payload.

---

## 5. What is next

**Read `docs/GAP-ANALYSIS.md` first.** It measures the product against the
master brief (`docs/superpowers/specs/2026-08-16-documents-master-spec.md`,
received 2026-08-16) and is more current than this section. Three findings
change the order of work:

- There is **no workflow/automation engine**, and the brief forbids a
  Documents-only approval engine. Documents §17–19 either wait for the platform
  engine or ship something that will have to be undone.
- **Projects do not exist at all** — a Tier-1 ERP gap that also blocks project
  folders, relationships, dossiers and field binding in Documents.
- **Departments are a free-text string** on `employees`, not an entity. Small
  to promote, and it unblocks a disproportionate amount of the brief.

### Documents (plan: `docs/superpowers/plans/2026-08-15-documents-core.md`)

Done: metadata, folders, relations, uploads, Library panel.
Remaining in phase 1: permissions (`Papers` group + `share`/`manage`), the
workspace screen, the API, documentation.

Then, in value order: versions and locking → comments and action centre →
workflow and approvals → signatures → sharing and security → templates as data
→ search.

**Deferred for missing prerequisites** — none of these exist in the codebase:
departments, projects, a PDF engine (PDF is `window.print()` today), DOCX,
a websocket tier, an AI provider, OCR, a search index.

Two I would argue against rather than merely defer: **realtime co-editing**
(months of OT/CRDT work plus a websocket tier, for something most tenants will
never have two people doing at once, on an offline-first PWA) and **DOCX
import/export** (the weakest value-per-effort item in the brief).

### ERP roadmap — 5 of 8 remaining

Till/shift management · budgets vs actual · job costing · FX gain/loss ·
customer portal.

### Older, still open

Hotel Management (no spec) · asset tracker with barcode labelling (gap analysis
only) · four modules with no API: fixed assets, bank reconciliation, stock
locations, reports.

### Decisions taken by default, easily reversed

Namespace `business_documents`; departments and projects cut; locking rather
than realtime; print-to-PDF kept. All recorded in the plan.

---

## 6. Working conventions

- Spec → plan → task-by-task with tests, committing each step
- **Full suite before every commit.** It takes ~6 minutes; run it in the
  background
- Regression gate is per-commit, not final — the six Papers test files must pass
  at every step of Documents work
- Never loosen an assertion to make a build pass; fix the generator
- Semantic colours (`positive`/`warning`/`negative`) are not brandable — a
  business must not be able to make "overdue" green

## 7. Key documents

| Path | What |
|---|---|
| `docs/GAP-ANALYSIS.md` | **Built / part-built / not built**, Documents and the whole ERP |
| `docs/superpowers/specs/2026-08-16-documents-master-spec.md` | The authoritative Documents brief + ERP checklist |
| `docs/API.md` | The whole API, 21 sections |
| `docs/branding.md` | Token contract, the three roles, adding a token |
| `docs/superpowers/specs/2026-08-15-documents-module-audit.md` | Capability map and blockers |
| `docs/superpowers/plans/2026-08-15-documents-core.md` | Current plan |
| `docs/superpowers/specs/2026-08-14-vip-membership-design.md` | VIP design |
