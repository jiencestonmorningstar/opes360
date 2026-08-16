# Gap sweep — handoff

**Date:** 2026-08-16 · Five small known-open gaps from earlier handoffs, closed.

## 1. CV download (recruitment-integration.md)

`Show::downloadCv()` on `app/Livewire/Recruitment/Show.php` streams the CV
straight off the private disk via `Storage::disk($application->cv_disk)->download()`
— no public URL ever exists. Gated `recruitment.view` inside the method as well
as in `mount()`, since Livewire actions arrive on their own requests. The file
goes out as the candidate's name plus the upload's extension (`Jean Mballa.pdf`),
so a folder of downloads reads as people. A Download button appears beside
"CV on file" in `resources/views/livewire/recruitment/show.blade.php`.

Tests (`RecruitmentScreensTest`):
- `test_the_cv_downloads_under_the_candidates_name`
- `test_an_outsider_cannot_fetch_the_cv` — a READ_ONLY user without
  `recruitment.view` is forbidden before the component even mounts.

## 2. Supplier links & max-level editor (supply-chain-integration.md)

Home chosen: **the replenishment screen** (`app/Livewire/Stock/Replenishment.php`),
not the product form. Why: the product form is a single-payload Alpine form built
for offline creation of catalogue facts — wedging a pivot table with a contact
lookup into it would break its shape — and the replenishment screen is where a
missing supplier is actually felt: the row that says "No supplier on file" now
carries the fix.

**Edit supply** on each row (visible only with `products.update`) opens an
inline panel: supplier (from supplier contacts — no second list), days to
deliver, last price, maximum level. Saving updates `items.max_level` and
creates/updates the link as preferred, demoting any other preferred link so the
plan always has one answer. `startEdit`/`saveEdit` both gate `products.update`;
the supplier lookup is tenant-scoped (`Contact::findOrFail`).

Tests (`ReplenishmentScreenTest`):
- `test_supply_details_can_be_edited_inline` — values persist, old preferred
  link demoted not deleted.
- `test_editing_supply_details_requires_products_update` — cashier forbidden.

## 3. Pricing page untruth (marketing-refresh.md)

`resources/views/marketing/pricing.blade.php` claimed "26 templates"; the real
count in `App\Support\DocumentTemplates` is **36**. Reworded to
"Business documents & letters (full template library)" — no number to drift,
matching the rebuilt features page's decision to state no counts.

## 4 & 5. Guides + search palette line

Two new guides, catalogued in `app/Support/Guides.php` and written in the
register of `asset-movements.md` (plain business WHY):

- `resources/guides/manufacturing.md` — "Making things": recipes, orders
  **snapshot the recipe at raise time**, completing is the one step that moves
  stock through **the same single stock ledger** as everything else, cost is
  component weighted-average, short components refuse the whole completion.
- `resources/guides/replenishment.md` — "Reordering before you run out":
  reorder/maximum levels, lead times, the **will-run-out-before-a-replacement**
  red rows, **one draft requisition per supplier**, nothing on the screen can
  spend money, and the new inline Edit supply panel.

`resources/guides/getting-started.md` gains one paragraph on the Ctrl+K
(Cmd+K) search palette. The catalogue↔files pairing is enforced by
`GuidesTest` — all 14 pass.

## Verify

`php artisan test --filter="Recruitment|Replenish|Guide|Marketing|Product"` —
98 passed (564 assertions). Pint clean.
