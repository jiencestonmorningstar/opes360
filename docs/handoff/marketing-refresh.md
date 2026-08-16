# Marketing refresh — handoff

**Date:** 2026-08-16 · **Scope:** `resources/views/marketing/*`, additive `faq()` on `MarketingController`.

## The one route line to add

In `routes/web.php`, next to the other marketing routes (around line 180):

```php
Route::get('/faq', [MarketingController::class, 'faq'])->name('marketing.faq');
```

The FAQ view links only through existing routes (`marketing.contact`, `marketing.pricing`),
so it renders fine before the route is wired; nothing else references `marketing.faq` yet.
Once wired, consider pointing the home footer's "FAQs" link (currently `route('help')`)
at it.

## Sector positioning, and the evidence behind each claim

Stated on **about** ("Who it is for") and echoed on **features** and the **FAQ**:
small and medium businesses in Cameroon and the wider OHADA / francophone-African
space.

| Sector | Evidence in the codebase |
|---|---|
| Shops & traders | `sales`, `products`, `stock_locations`, `loyalty` modules (config/modules.php) |
| Secretariats & print bureaus | `partners` module + partner programme (`config/opes.partners`, partners.blade.php) |
| Service firms & workshops | Service desk + walk-in QR triage `/triage/{token}` (GAP-ANALYSIS §16) |
| Clinics & pharmacies | Batch/lot, serials, expiry, FEFO picking (GAP-ANALYSIS Tier 1 #6) |
| Schools & professional practices | Projects module, papers/templates, payroll (modules.php) |
| NGOs & project-driven organisations | Projects, procurement/requisitions, audit trail with 10-year money floor (audit-trail.md guide) |
| Businesses with field technicians | Fleet as extension of fixed assets: trips, fuel, distance servicing (GAP-ANALYSIS #15) + service desk |
| Country grounding | SYSCOHADA chart, TVA, CNPS/IRPP, XAF/FCFA + MTN/Orange money (modules.php, pricing, about) |

**Stated as NOT for (yet):** heavy manufacturers (Tier 2 #13 = C, nothing built) and
large groups wanting deep multi-entity consolidation. Deliberately on about,
features and the FAQ — honesty as positioning.

Left out on instruction: manufacturing, supply chain, global search (agents mid-build).

## Untrue or ungrounded content found and removed

- **home.blade.php stats band** — "500+ Businesses", "10K+ Users Worldwide",
  "1M+ Transactions Processed", "100% Paperwork Eliminated": invented adoption
  figures. Replaced with product-grounded figures (module count read from
  `config/modules.php`, 10-year money audit retention, offline invoicing, FCFA
  pricing).
- **home.blade.php industries** — "Manufacturing" listed as a served industry;
  GAP-ANALYSIS marks manufacturing **C — nothing**. Replaced with
  "Secretariats", which the partner programme genuinely serves.
- **features.blade.php (old)** — claimed counts ("26 templates", "98 business-card
  designs") dropped rather than re-verified; the page no longer states counts that
  can drift. The rebuilt page is grounded card-by-card in `config/modules.php`
  descriptions and the guides.
- **SMS/WhatsApp** — never claimed before, and the FAQ now says plainly: not yet
  (no paid gateway; notifications are email + in-app), per GAP-ANALYSIS #21.

Note: `pricing.blade.php` still says "26 templates" in its module table — out of
this task's scope beyond the FAQ pointer, flagged here rather than edited.

## What changed, file by file

- `resources/views/marketing/features.blade.php` — rebuilt around the real
  catalogue, grouped as a business thinks (Selling / The money / The people /
  The things / The obligations / Working together), plus an honest "what it
  does not do yet" section. Distinctive features included: approvals where
  being asked is the permission, contract notice-date watch, walk-in QR triage,
  audit trail with ten-year money retention, per-currency requisition
  thresholds, offline-safe numbering, module switches.
- `resources/views/marketing/about.blade.php` — beliefs extended from three to
  six (extend-don't-duplicate, real controls, module switches) and a sector
  section added, including who it is not for.
- `resources/views/marketing/faq.blade.php` — **new**, 17 questions in four
  sections, answers grounded in the guides; `<details>` accordions, house
  Tailwind conventions, no new components.
- `app/Http/Controllers/MarketingController.php` — additive `faq()` only.
- `resources/views/marketing/home.blade.php` — only the untrue lines fixed
  (stats band, Manufacturing industry).
