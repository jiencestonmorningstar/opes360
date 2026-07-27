# OPES360 — Phased Build Plan

**Product:** OPES360 — Business Identity & Operations Suite
**Developed by:** Opesware Technologies (https://opesware.com)
**Stack:** Laravel 12 · PHP 8.4 · MySQL 8.4 LTS · Livewire 3 · Alpine.js · Tailwind · PWA (Service Worker + IndexedDB)
**Document status:** v1 draft. Flows will be attached per phase under `docs/flows/` as they arrive.

---

## 1. How this plan is organised

The spec lists 16 modules. Modules are a good way to describe a product and a bad way to build one, because
they hide the dependencies. This plan re-cuts the same scope into **13 phases grouped into 4 releases**,
ordered so that every phase ships something demonstrable and nothing has to be retrofitted later.

| Release | Phases | Goal | Rough duration |
|---|---|---|---|
| **R0 — Foundation** | 0 | Nothing user-visible; the decisions that are expensive to change | 2–3 weeks |
| **R1 — Identity (Alpha)** | 1–3 | A business can create its identity and print its stationery | 7–10 weeks |
| **R2 — Operations (Beta)** | 4–7 | A business can sell, invoice, get paid and issue receipts — offline | 10–14 weeks |
| **R3 — Depth (GA)** | 8–12 | AR, artisans, reporting, AI, document generation | 12–16 weeks |
| **R4 — Growth (Post-GA)** | 13 | Directory & marketplace | separate planning |

Durations assume a small team (2 backend/full-stack, 1 frontend, 1 designer, part-time QA). They are
planning estimates, not commitments.

### The three ordering rules behind this sequence

1. **Tenancy, roles and audit go in Phase 0.** Multi-company (Module 14), user roles (Module 15) and audit
   logging are cheap to build on day one and brutally expensive to retrofit — every query, every policy and
   every migration written before them has to be revisited. They are not "later modules".
2. **The offline engine ships before the documents that need it.** Sales documents and receipts are the
   things that genuinely must work in a market stall with no signal. If offline is bolted on after the
   invoice module exists, the invoice module gets rewritten. So Phase 4 builds the sync substrate and proves
   it on low-risk data (customers, products), and Phases 5–7 are offline-native from their first commit.
3. **AR is deferred to R3.** It is the least proven part of the spec, has the largest unknowns, and blocks no
   revenue. QR verification delivers ~90% of the trust value in Phase 1. See `architecture/qr-ar.md`.

---

## 2. Phase breakdown

### Phase 0 — Foundation *(2–3 weeks)*

No end-user features. This phase exists to make Phases 1–13 fast.

**Scope**
- Laravel 12 skeleton, PHP 8.4, MySQL 8.4, Docker/Sail dev environment, `.env` conventions.
- CI: Pest, PHPStan (level 6+), Laravel Pint, migration check on every PR.
- Auth: registration, login, password reset, email verification, TOTP two-factor.
- **Tenancy:** `companies` table + `company_id` on every business table + a global scope + a "current company"
  session switcher. Single database, row-level isolation (see `architecture/decisions.md` #2).
- **Roles & permissions:** the 7 roles from Module 15, backed by a permission table, enforced with Laravel
  policies and Gates. Seeded, not hardcoded.
- **Audit log:** a single `activity_log` table written by a model observer, capturing actor, company, model,
  before/after and IP.
- **ID strategy:** ULID primary keys on everything that can be created offline; auto-increment elsewhere.
- Storage abstraction (local ↔ S3-compatible), queue worker, scheduler, mail transport.
- Mobile-first base layout: bottom tab bar, safe-area handling, one shared Tailwind design token file.

**Done when:** a user can register, create two companies, invite a user with a limited role into one of them,
and every action is in the audit log. CI is green on a clean checkout.

---

### Phase 1 — Business Identity & QR Verification *(3–4 weeks)* — Module 1 (data), 3, 4

The first phase that produces something you can show a customer.

**Scope**
- Company onboarding wizard, mobile-first, resumable: name, motto, description, industry, registration
  number, TIN, VAT, address, GPS, website, email, phones, socials, operating hours.
- Logo **upload** (generation comes in Phase 2 — don't block onboarding on it).
- **Verification tokens:** every verifiable entity gets an immutable public token. One token scheme, used by
  QR now and AR later. See `architecture/qr-ar.md`.
- **QR generation:** business QR, contact QR (vCard), website QR, payment QR. Server-rendered SVG, cached to
  storage, embeddable at print resolution.
- **Public business profile** at `/business/{slug}` — logo, banner, description, contacts, hours, map link,
  socials, verification badge, save-contact (vCard), share. Server-rendered, fast, no auth, cacheable.
- **Verification pages** at `/v/{token}` — a generic verifier that resolves a token to whatever it points at
  and renders the correct panel. Built once here; every later document type plugs into it.

**Deliberately deferred:** products/services/reviews/portfolio sections of the profile (they need Modules 8/9,
arriving in Phase 5). The profile ships with the sections that only need company data.

**Done when:** scanning a printed business QR on a phone opens a fast, correct public profile with a
verification badge.

---

### Phase 2 — Logo Generator & Brand Kit *(3–4 weeks)* — Module 1 (AI), Module 12 (partial)

**Scope**
- **Parametric SVG logo composer:** curated industry template sets (the 10 industries in the spec) × layout ×
  icon × typeface × palette, composed server-side into clean SVG. This is the workhorse and it is what makes
  SVG/print export actually possible.
- **AI assist layered on top:** the model proposes company names, taglines, palettes, industry-appropriate
  icon and font pairings, and ranks candidates. It selects and configures templates rather than drawing
  pixels. Rationale and the raster-generation trade-off are in `architecture/decisions.md` #5.
- Export: SVG, PNG (1×/2×/4×, transparent), PDF.
- **Brand kit:** palette with accessible contrast pairs, type scale, icon set, auto-generated brand
  guidelines PDF.
- Brand tokens persisted per company and consumed by every later template (stationery, documents, profile).

**Done when:** a new business goes from name to downloadable logo + brand guidelines PDF in under 3 minutes on
a phone.

---

### Phase 3 — Business Stationery *(3–4 weeks)* — Module 2

**Scope**
- **Letterhead:** A4 and A3, multiple templates, live preview, PDF (print-ready, with bleed and crop marks)
  and DOCX export.
- **Business cards:** front/back per the spec, with QR (AR slot rendered but inert until Phase 8), standard
  85×55mm with bleed, print-ready PDF, and a shareable digital card.
- **Email signature:** HTML generator with copy-to-clipboard, plus per-client (Gmail/Outlook/Apple Mail)
  install instructions.
- **Company stamp:** circular, square, oval; PNG with transparency and SVG.
- **Print pipeline established here** and reused by Phases 6–7: headless Chromium rendering with CSS `@page`
  for server-side PDFs. See `architecture/decisions.md` #4.

**Done when:** a print shop accepts the exported PDFs without asking for changes.

> **R1 / Alpha ships here.** Identity + branding + stationery is already a sellable product on its own.

---

### Phase 4 — Offline Engine & PWA *(3–4 weeks)* — PWA section

The technical heart of the product. Nothing new appears in the UI except a sync indicator; everything after
this phase depends on it.

**Scope**
- Service worker: app-shell precache, runtime caching strategy per route class, update prompt.
- Web app manifest, install prompt, icons, splash, standalone display.
- **IndexedDB local store** mirroring the entities that must work offline.
- **Outbox / sync queue:** client-generated ULIDs, idempotency keys, ordered replay, exponential backoff,
  poison-message quarantine.
- **Sync API:** a versioned pull/push endpoint pair with per-entity cursors and server-side conflict rules.
- **Document number leasing** — the hard problem. A device offline must be able to hand a customer a receipt
  with a final, non-provisional number. Design in `architecture/offline-sync.md`.
- Conflict policy: drafts are last-write-wins with version checks; **issued documents are immutable** (voided
  or credit-noted, never edited), which removes almost all real conflicts by construction.
- Device authorization + per-device sync tokens, remote revoke.
- Sync status UI: online/offline, pending count, last sync, per-record error surfacing.
- **iOS reality check:** Safari has no Background Sync API. Foreground sync on `visibilitychange` + focus is
  the primary path on iOS; Background Sync is a progressive enhancement on Chromium.

**Proven on:** customers and products (built in Phase 5) — chosen because a bad merge there is recoverable,
unlike a bad merge on an issued invoice.

**Done when:** airplane mode for an hour, create and edit records, come back online, and everything lands
exactly once with no duplicates and no lost edits. This has an automated test, not just a manual one.

---

### Phase 5 — CRM, Products & Services *(3–4 weeks)* — Modules 8, 9

**Scope**
- Customers, suppliers, vendors, leads; contact history timeline.
- Products and services, categories, images, pricing, tax rules, discounts, barcodes.
- Basic inventory: stock levels, movements, low-stock alerts.
- All of it offline-native, riding the Phase 4 engine.
- Backfills the products/services sections of the public business profile from Phase 1.

**Done when:** a full catalogue can be built and edited on a phone with no connection.

---

### Phase 6 — Sales Documents *(4–5 weeks)* — Module 6

**Scope**
- Quotation, invoice, proforma, purchase order, delivery note, credit note, debit note — one document engine,
  seven types, differing in numbering, workflow and wording rather than in code paths.
- Auto-numbering with per-company, per-type, per-year formats + offline leasing from Phase 4.
- Line items, taxes, discounts, multi-currency-ready totals, terms.
- Document lifecycle: draft → issued → sent → accepted/paid/void, with **immutability at issue**.
- PDF generation (server) + browser print (offline path).
- QR verification on every document, wired into the Phase 1 verifier.
- Digital signature, approval workflow, conversion (quotation → invoice → receipt), customer history.

**Done when:** a quotation created offline converts to an invoice, syncs, generates an identical PDF
server-side, and its QR verifies publicly.

---

### Phase 7 — Payments & Smart Receipts *(3–4 weeks)* — Modules 7, 10

**Scope**
- Payment recording: cash, bank transfer, mobile money, card; part-payments and allocation to invoices.
- Payment receipts and confirmations; outstanding balance reports.
- **Receipt formats:** A4, A5, thermal 58mm, thermal 80mm — each with its own tuned template.
- QR (and AR slot) on every receipt; verification exposes authenticity, amount, customer, business, method,
  date and status per the spec.
- **Thermal printing caveat, flagged now rather than at launch:** Web Bluetooth and WebUSB do not exist in
  iOS Safari. On Android/Chromium we can drive ESC/POS directly; on iOS the supported path is rendering the
  receipt to an image and handing it to the printer vendor's own app via the share sheet. This needs a
  product decision — see `architecture/decisions.md` #7.

> **R2 / Beta ships here.** Identity + stationery + offline sales + receipts is the complete core product.

---

### Phase 8 — AR Identity Layer *(4–6 weeks)* — Module 3 (AR half)

Deferred to here on purpose: highest uncertainty, zero revenue blocking.

**Scope**
- Marker generation per company/document, printed alongside the QR.
- Web-based AR experience: WebXR where supported, marker-tracked overlay otherwise, and a **graceful 2D
  fallback that is genuinely good** — because a meaningful share of scans will land there.
- Overlay content per the spec: logo, verification status, registration details, contacts, gallery, services,
  optional video, navigation, call/WhatsApp/email/save-contact/share actions.
- AR analytics: scans, devices, locations.

**Prototype spike (1 week) precedes committed scope.** If tracked AR does not hit the quality bar on
mid-range Android hardware, ship the enhanced 2D experience under the same code and revisit.

---

### Phase 9 — Artisan Digital Profiles *(2–3 weeks)* — Module 5

Full artisan profile per spec, `/artisan/{slug}`, artisan ID numbers, portfolio media, testimonials, coverage
area, vCard, quotation request and appointment booking hooks into Phase 6. Reuses the Phase 1 profile and
verification infrastructure, which is why it is cheap by this point.

---

### Phase 10 — Dashboard & Reports *(3–4 weeks)* — Modules 11, 16

Dashboard (sales, revenue, receivables, growth, customer stats, inventory alerts, sync status) and the eight
report types with PDF/Excel/CSV export. Placed late because it needs real data flowing through Phases 5–7 to
be designed honestly. Pre-aggregation strategy for reports is a decision made in this phase, once query
shapes are known.

---

### Phase 11 — AI Assistant *(2–3 weeks)* — Module 12

Broadens the Phase 2 AI foundation to descriptions, quotation and invoice wording, business emails, customer
messages, payment reminders and marketing content. Per-company usage metering and cost controls belong here.

---

### Phase 12 — Document Generator *(2–3 weeks)* — Module 13

Contracts, service agreements, employment letters, company profiles, certificates, meeting minutes, business
proposals. A template engine over the Phase 3 print pipeline plus the Phase 11 AI layer — largely assembly by
this stage.

---

### Phase 13 — Hardening & Launch *(3–4 weeks)*

Security review and penetration testing, tamper detection on documents, encryption of sensitive fields,
automated backup and restore rehearsal, performance and load testing, accessibility pass, Lighthouse PWA
budget, monitoring and error tracking, onboarding polish, help content, billing/subscription if commercial at
launch.

> **R3 / GA ships here.**

---

### Phase 14 — Business Directory & Marketplace *(post-GA, separately planned)*

The spec's own "natural next enhancement": searchable directory across business and artisan profiles by
industry, location, service, rating and availability, turning verification pages into lead generation. Worth
building only once there is a real population of profiles.

---

## 3. Critical path and parallelism

```
Phase 0 ──┬─> Phase 1 ──> Phase 2 ──> Phase 3 ────────────────┐
          │                                                   │
          └─> Phase 4 ──> Phase 5 ──> Phase 6 ──> Phase 7 ──> Phase 10 ──> Phase 13
                                          │
                          Phase 8 ────────┤ (independent after Phase 1)
                          Phase 9 ────────┤ (needs Phase 1 + 6)
                          Phase 11 ───────┤ (needs Phase 2)
                          Phase 12 ───────┘ (needs Phase 3 + 11)
```

The identity track (1→2→3) and the operations track (4→5→6→7) are independent after Phase 0 and can run in
parallel with two streams. Phases 8, 9, 11 and 12 are all parallelisable once their prerequisites land, which
is what makes R3 compressible if the team grows.

---

## 4. Risk register

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| 1 | Offline sync correctness — duplicates, lost edits, number collisions | Severe; corrupts financial records and destroys trust | Immutable issued documents; ULIDs + idempotency keys; number leasing; automated offline test suite in Phase 4 |
| 2 | Offline document numbering vs. tax compliance | High; sequence gaps may be challenged by revenue authorities | Auditable lease ledger recording every allocated, used and voided number; validate against target jurisdictions early |
| 3 | AI logo output quality | High; a weak logo generator undermines the headline feature | Parametric SVG templates as the floor, AI as the selector; human-curated template library |
| 4 | AR feasibility on mid-range Android | Medium; a differentiator may not land | 1-week spike before committing Phase 8 scope; strong 2D fallback |
| 5 | iOS PWA limitations (no Background Sync, no Web Bluetooth) | Medium; degraded experience for a large user base | Foreground sync design from day one; explicit iOS printing path decided in Phase 7 |
| 6 | Print fidelity (bleed, CMYK, fonts) | Medium; rejected artwork at print shops | Headless Chromium pipeline; test with a real print shop at the end of Phase 3 |
| 7 | Scope breadth — 16 modules is a large surface | Medium; slipping GA | Release gates at R1 and R2; each is independently sellable |

---

## 5. What I need from you next

The plan above is structural. Your flows will make it concrete. Most useful, in this order:

1. **Onboarding flow** (Phase 1) — signup → company creation → first asset. Highest leverage; sets navigation
   and information architecture for everything else.
2. **Sales document flow** (Phase 6) — quotation → invoice → receipt, including who approves what.
3. **Offline scenarios** (Phase 4) — describe the actual field situations you expect. How long offline? One
   device or several per company? Does a receipt get handed over while offline? These answers decide the
   numbering design.
4. **Verification landing experience** (Phases 1/8) — what a customer scanning a receipt QR should see and be
   able to do.

Drop them into `docs/flows/` (there is a template in `docs/flows/README.md`) or just paste them; I'll fold
each into the relevant phase and expand that phase into implementable tasks.

### Open questions blocking detail work

- **Jurisdiction(s) at launch** — drives tax model, invoice legal requirements and receipt numbering rules.
- **Multi-currency at launch or later?** Cheap to design for now, expensive to add after Phase 6.
- **Mobile money providers** to integrate, and whether Phase 7 is record-only or live payment integration.
- **Commercial model** — free/paid tiers, and whether billing must exist at GA (affects Phase 13).
- **AR: tracked overlay or enhanced 2D?** Determines whether Phase 8 is 4 weeks or 6+.

---

## 6. Related documents

- `architecture/decisions.md` — key technical decisions and their trade-offs
- `architecture/data-model.md` — core schema sketch
- `architecture/offline-sync.md` — sync protocol and number leasing
- `architecture/qr-ar.md` — verification token scheme, QR and AR design
- `flows/` — your flows, folded into phases
