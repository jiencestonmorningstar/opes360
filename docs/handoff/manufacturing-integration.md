# Handoff — manufacturing (bills of materials and production orders)

Small-business manufacturing: a recipe per finished product, and orders that
run it. Completing an order consumes the components and receives the finished
goods **through the existing stock movement ledger**, in one transaction.

## Which existing stock machinery is reused

- **`App\Services\Stock\StockValuation`** — availability ("can we make N")
  reads `quantities()`, and component costing reads `unitCosts()`. The
  finished good's cost is the sum of component quantities × their existing
  weighted average; it rides in on the receipt movement's `unit_cost` exactly
  like a supplier delivery, so the valuation prices finished goods with the
  machinery it already has. **No second costing engine.**
- **`App\Services\Stock\BatchLedger`** — a lot-tracked component is consumed
  via `pick()` (the existing FEFO path, movements referenced to the order), and
  a lot-tracked finished good is received via `receive()` with a lot number the
  user supplies at completion.
- **The append-only `StockMovement` ledger** — untracked components and
  finished goods move as ordinary movements with `reason = 'production'` and
  `reference_type/reference_id` pointing at the order, the same shape
  `Stocktaker` writes. Nothing manufacturing-shaped stores a quantity;
  `stockOnHand()`, the count sheet and the valuation all just see movements.
- A short component refuses the whole completion by name
  (`"Not enough Vis 40mm: 48 needed, 30 on hand."`) before anything moves;
  cancelling consumes nothing.

## Permission keys — a `Manufacturing` group

Add to `App\Support\Permissions::CATALOGUE`:

```php
/*
 * Making things. `complete` is split from `manage` because completing is
 * the money-shaped act: it is the moment real stock moves and a cost is
 * fixed onto the finished goods. A workshop hand can write recipes and
 * raise orders all day; saying "it is made, take the planks off the
 * shelf" is the same trust as posting a stocktake, and should stop at
 * whoever is trusted with `products.adjust-stock` today.
 */
'Manufacturing' => ['view', 'manage', 'complete'],
```

I argue **against** a separate `create-bom` vs `create-order` split: for the
target business they are one job, and the catalogue's own style (Stocktake,
Service) splits only where the second act commits money or stock. `complete`
is that act here.

Seeder suggestion: `view` wherever `products.view` goes, `manage` with
`products.update`, `complete` with `products.adjust-stock`.

## Routes

`routes/web.php`, inside the authenticated group:

```php
use App\Livewire\Manufacturing\Index as ManufacturingIndex;

Route::get('/manufacturing', ManufacturingIndex::class)->name('manufacturing');
```

No route middleware needed: the component calls
`Gate::authorize('manufacturing.view')` in `mount()`, `manufacturing.manage`
on every write, and `manufacturing.complete` on `complete()`.

## config/modules.php entry

```php
'manufacturing' => [
    'label' => 'Manufacturing',
    'description' => 'Recipes for what you make, and orders that consume components and stock the finished goods.',
    'icon' => 'cube',
    'default' => false,
    // A recipe is written between catalogue products and completion moves
    // their stock — without the products module there is nothing to make
    // anything from, or into.
    'requires' => ['products'],
    'groups' => ['manufacturing'],
    'models' => [\App\Models\BillOfMaterial::class, \App\Models\ProductionOrder::class],
],
```

**Off by default, arguing:** most OPES360 businesses (shops, salons,
consultancies) buy finished goods; only a workshop/bakery/assembler needs
this, and the module catalogue's stated rule is that every module beyond the
account is weight for everyone else. `requires: ['products']` for the reason
in the comment.

## Navigation

Next to Products / stock:

```php
['label' => 'Manufacturing', 'route' => 'manufacturing', 'icon' => 'cube', 'can' => 'manufacturing.view'],
```

The screen renders with `'active' => 'manufacturing'`; if you prefer it to
live under the products cluster, change the layout's `active` to `'products'`.

## Guide topic (resources/guides/)

Suggested slug `making-things`: what a recipe is (components + quantities +
optional scrap %), that raising an order moves nothing, that completing is the
one step that consumes and stocks — refusing if a component is short — and
that the finished good's cost is what its components were worth.

## What was built

| File | What it is |
|---|---|
| `database/migrations/2026_09_13_000101_create_manufacturing_tables.php` | `bill_of_materials`, `bill_of_material_lines`, `production_orders`, `production_order_lines`. No quantities stored anywhere. |
| `app/Models/BillOfMaterial.php` (+ `BillOfMaterialLine`) | The recipe; `demandFor($units)` computes component demand incl. scrap, per `output_quantity` run size. |
| `app/Models/ProductionOrder.php` (+ `ProductionOrderLine`) | One run: `MO-2026-0001`, planned → in-progress → completed/cancelled. Lines snapshot the recipe at raise time; cost frozen at completion. |
| `app/Services/Manufacturing/Production.php` | `create`, `start`, `complete`, `cancel`, `availability` (read model), `makeable` ("can make N now"). All stock movement lives here. |
| `app/Livewire/Manufacturing/Index.php` + `resources/views/livewire/manufacturing/index.blade.php` | Orders + Recipes tabs, house style; service calls, `RuntimeException → addError`. Lot-tracked finished goods prompt for a lot number before completing. |
| `tests/Feature/Manufacturing/ProductionTest.php` | 12 tests. Gates are `Gate::define`d in `setUp` so they exercise the feature, not the wiring. |

Additive edits only to `app/Models/Item.php`: `billsOfMaterials()` and
`productionOrders()` relations.

## Deliberate limits (small-business honest)

- No routing/work-centres/operations, no labour or overhead in the cost —
  component cost only. Add a flat overhead line as a component if needed.
- No partial completion; an order completes whole or not at all.
- Completing does not itself post a journal entry: the intermittent-inventory
  stance (`Stocktaker`) is that the count posts stock to the books, and
  production movements flow into the next count's valuation like every other
  movement.
- One level of BOM at a time works naturally (a sub-assembly is just a product
  with its own recipe and orders); there is no automatic multi-level explosion.
