# Supply-chain replenishment — integration notes

ERP checklist #14: "Nothing beyond reorder levels." There now is.

## What shipped

| Piece | File |
| --- | --- |
| Migration: `item_suppliers` link table + `items.max_level` | `database/migrations/2026_09_13_000201_create_item_supplier_links.php` |
| Item ↔ supplier link model (lead time, last price, preferred) | `app/Models/ItemSupplier.php` |
| `Item::supplierLinks()`, `Item::preferredSupplierLink()`, `max_level` cast | `app/Models/Item.php` (additive only) |
| Read model: below/falling items, usage, days of cover, "will run out before delivery" flag, suggested quantity | `app/Support/Replenishment.php` |
| Accepting suggestions → draft requisitions, one per supplier, via the existing `Requisitions::create()` | `app/Services/Procurement/Replenisher.php` |
| Screen | `app/Livewire/Stock/Replenishment.php` + `resources/views/livewire/stock/replenishment.blade.php` |
| Tests | `tests/Feature/SupplyChain/ReplenishmentTest.php`, `ReplenishmentScreenTest.php` |

Suppliers are contacts — no second supplier list. Accepting a suggestion creates a
**draft `PurchaseRequisition`** through `App\Services\Procurement\Requisitions::create()`;
it submits, thresholds and approves through the shared workflow engine exactly like a
typed requisition. Nothing on this path can mint a purchase order or commit money.

## The calculation, briefly

- `available` = movement-ledger sum − live (`holding()`) reservations.
- `daily_usage` = sales-ledger movements (`sale`/`credit`/`document-void`) over the last
  30 days ÷ 30. Transfers and count adjustments are not consumption.
- Included when available ≤ reorder level, **or** projected to cross it within the
  preferred supplier's lead time.
- `will_run_out` = days of cover < lead days — the rows highlighted red.
- `suggested_quantity` = (max_level ?? reorder_level) − available + usage × lead days,
  priced from the link's `last_price`, falling back to the catalogue cost.

## Route (owned by the integrating agent — routes/* untouched)

```php
Route::get('/stock/replenishment', App\Livewire\Stock\Replenishment::class)
    ->name('stock.replenishment');
```

Place it alongside `products.stock.value` / `products.stock.count` in the authenticated,
company-scoped group.

## Permissions — existing keys suffice, none added

- **See the screen:** `products.view` (`mount()`). It is a stock report — the same class
  of thing as the valuation screen, and every seeded role that runs a shop has it.
- **Accept suggestions:** `procurement.requisition-manage` (checked on `accept()` and for
  showing the button). Raising a requisition from this screen is the identical act to
  raising one from the procurement screen, so it must be the identical permission — a
  separate `replenish` key would let an administrator grant a second, quieter way to ask
  for spending. No `approve` anywhere, as ever: the workflow is the approval.

## Modules placement — with products, argued

The screen lives under Stock (`app/Livewire/Stock`, nav active state `products`) because
its audience is the storekeeper answering "what do I reorder today?", next to valuation
and counts. Its *output* is procurement's, and the module gate agrees: viewing needs only
the products side, while accepting sits behind `procurement.requisition-manage`, whose
gate the procurement module already guards. A company without the procurement module gets
the early-warning list read-only, which is correct — the list is information, the button
is spending.

Nav suggestion: a "Replenishment" entry in the Products/Stock section, badged with the
count of `will_run_out` rows if a badge is cheap.

## Guide topic

`resources/guides/` untouched per ownership. Suggested topic: **"Reordering before you
run out"** — set a reorder level (and optionally a maximum) on a product, name a supplier
with their delivery time on the product, read the replenishment screen (red rows = the
shelf empties before a delivery could land), tick rows, raise requisitions, then submit
them from Procurement as usual.

## Verify

```powershell
$env:Path = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;$env:Path"
php artisan test --filter="Replenish|SupplyChain|Stock|Procurement|Requisition"
```

148 passed (371 assertions) at handover. Pint clean on all new/touched files.

## Open edges

- No UI yet for editing `item_suppliers` rows or `max_level`; both are plain columns the
  products edit screen can grow inputs for (additive, no service needed beyond the model).
- The workflow-listener registration (`SyncApprovedRequisitions`) is still test-local, as
  in the existing procurement tests — the provider line belongs to the integrating agent.
