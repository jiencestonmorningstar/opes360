# Sector-based onboarding — handoff

## What landed

- `app/Support/Sectors.php` — the sector catalogue (12 entries grounded in the
  marketing pages' list, plus `everything` as the skip default). Each entry is
  `on` (modules beyond the defaults) and `off` (default-on modules the sector
  has no use for). `modulesFor()` closes `on` over `config/modules.php`'s
  `requires` chains; `apply()` writes the departures into the company's
  `modules` json (explicit true/false per touched key only — the
  departures-from-default semantic in ModulesTest is preserved) and stores the
  slug in the new nullable `companies.sector` column
  (`2026_08_17_000201_add_sector_to_companies.php`).
- Register step 2: optional sector cards, applied inside the existing
  transaction. Skipped/unknown → `everything` (today's defaults, no
  departures written).
- Dashboard: session-borne (`onboarding.welcome`) dismissible first-run card
  listing the switched-on modules and pointing at Settings → Modules.
- Settings → Modules: sector label + "Reset to sector defaults"
  (`resetModulesToSector`, gated `settings.update`; replaces the stored
  departures wholesale).
- `DemoAccountProvisioner::provision(..., ?string $sector = null)` and
  Business → Companies creation (`newSector`) take the same optional
  application.
- Tests: `tests/Feature/SectorOnboardingTest.php` (dependency closure across
  every sector, skip = defaults, first-run card, reset, gating, and the pin
  that the sector is never re-applied after signup).

## The contract

The sector guides, it does not lock. It is applied exactly once at company
creation; the stored slug is only a label and the reset target. Settings →
Modules stays the single source of truth.

## Deliberately not touched (per instructions)

- `routes/`, `config/opes.php` navigation, `app/Support/Permissions.php`,
  providers. Nothing was needed from them: the reset action reuses the
  existing `settings.update` ability and the module gate needed no changes.
- "Lot tracking" is not a module — it is per-product tracking inside
  Products & stock (`Item::TRACKING_BATCH`), so the clinic/pharmacy sector
  simply keeps `products` on and says so in its description.

## If someone extends this

- Adding a sector: one array entry in `Sectors::catalogue()`. The closure
  test in SectorOnboardingTest will fail the build if its `on` list ever
  yields a module missing a requirement.
- Adding a module to `config/modules.php` needs no sector changes: sectors
  store only departures, so new modules arrive with their catalogue default.
