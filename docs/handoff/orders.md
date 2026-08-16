# Handoff — orders (supply chain outbound: sales orders & fulfilment)

The outbound half of supply chain: a customer order that is confirmed against
real stock, delivered with a printed, QR-verified delivery note, and invoiced
as an ordinary Document. **Outbound mirrors inbound** — every claim below is
about existing machinery being consumed, not reimplemented.

## Which existing machinery is reused (the non-negotiables, kept)

- **`App\Services\Stock\StockReservations`** — confirming an order reserves
  through `reserve()`, one live `StockReservation` per order line
  (`reference_type = SalesOrderLine`). There is **no parallel hold table**;
  the line's `quantity_reserved` column is its running view of those rows.
  What cannot be held becomes `quantity_backordered` — a named, visible
  remainder returned from `confirm()` as `[{item, short}]`, never a silent
  truncation. Cancelling calls `releaseFor()`; delivering calls `fulfil()` /
  shrinks the hold.
- **The append-only `StockMovement` ledger** — delivering writes ordinary
  movements, `reason = 'sale'`, referenced to the DeliveryNote, `unit_cost`
  null (same stance as `StockLedger`: cost is a question about the stock, not
  the sale). Stock on hand, `StockValuation` and `Replenishment`'s usage
  figures all see an order delivery without knowing orders exist.
- **One invoice generator** — `Fulfilment::invoice()` drafts an ordinary
  `Document` (type Invoice) from the delivered-not-yet-invoiced quantities,
  priced through `App\Support\Vat`, linked via the `sales_order_invoices`
  table, and issued through the one `DocumentIssuer`. The document lines
  deliberately carry **no `item_id`**: the stock already moved at delivery,
  and an item-bearing line would make the issuer's StockLedger hook take it
  off the shelf a second time. (Same pattern as `ServiceBilling`.)
- **`App\Services\DocumentNumbers`** — `OrderNumbers` subclasses the lease
  ledger (like `TicketNumbers`): `SO-` for orders, `DLV-` for delivery notes
  (`DN-` already belongs to `DocumentType::DeliveryNote`'s series).
- **`VerificationToken`** — every delivery note mints one exactly as
  `DocumentIssuer` does, so the printed QR resolves through the existing
  `/v/{token}` page.
- **`App\Support\Replenishment`** — `FulfilmentBoard` reads its `rows()` (not
  a fork) to answer "is this shortage already covered by a reorder?".
- **`App\Support\Watermarks` doctrine** — `DeliveryNote::statusMark()`
  (VOID / COPY, no DRAFT because a delivery note has no draft state) and the
  print view render it fixed on every page, print-color-adjust exact.

## Permission keys — an `Orders` group

Add to `App\Support\Permissions::CATALOGUE`:

```php
/*
 * Customer orders and fulfilment. Two money-shaped acts are split out:
 * `confirm` commits real stock to a customer (the moment availability
 * drops for everyone else), and `deliver` is the moment goods physically
 * leave and movements are written — the same trust as posting a
 * stocktake. A sales clerk drafts and invoices with `manage`; holding
 * and moving the shelf stops at whoever is trusted with stock today.
 */
'Orders' => ['view', 'manage', 'confirm', 'deliver'],
```

Seeder suggestion: `view`/`manage` wherever `sales.*` goes; `confirm` and
`deliver` to Manager, following the house doctrine of Manager-minus-the-money
acts being the default.

Gate usage: `orders.view` on both screens' `mount()` and the print
controller; `orders.manage` on drafting, invoicing, cancelling;
`orders.confirm` on confirm + reserve-backorders; `orders.deliver` on
delivering. (Tests `Gate::define` these in `setUp` so they exercise the
feature, not the wiring.)

## Routes

`routes/web.php`, inside the authenticated group:

```php
use App\Http\Controllers\Orders\DeliveryNotePrintController;
use App\Livewire\Orders\Index as OrdersIndex;
use App\Livewire\Orders\Show as OrderShow;

Route::get('/orders', OrdersIndex::class)->name('orders');
Route::get('/orders/{orderId}', OrderShow::class)->name('orders.show');
Route::get('/orders/delivery-notes/{note}/print', DeliveryNotePrintController::class)
    ->name('orders.delivery-note');
```

`{orderId}` is a plain string param (the Show component scopes through the
tenant global scope via `findOrFail`); `{note}` route-model-binds
`DeliveryNote`, which carries `BelongsToCompany`.

## config/modules.php entry

```php
'orders' => [
    'label' => 'Sales orders',
    'description' => 'Customer orders confirmed against stock, delivered with printed delivery notes, invoiced from what actually went.',
    'icon' => 'truck',
    'default' => false,
    // Confirming holds catalogue stock and invoicing goes out through the
    // sales document path — without either there is nothing to promise or
    // to bill.
    'requires' => ['products', 'sales'],
    'groups' => ['orders'],
    'models' => [\App\Models\SalesOrder::class, \App\Models\DeliveryNote::class],
],
```

**Off by default, arguing:** the order-then-deliver-then-invoice cycle is a
distributor/wholesaler shape. A shop sells over the counter through the sales
screen and never backorders; shipping the module on would put a second
"orders" concept in every such business's navigation.

## Navigation

Next to Sales / Products:

```php
['label' => 'Orders', 'route' => 'orders', 'icon' => 'truck', 'can' => 'orders.view'],
```

Both screens render with `'active' => 'orders'`.

## Guide topic (resources/guides/)

Suggested slug `customer-orders`: draft promises nothing; confirming reserves
what exists **and names what is short** (the backorder — visible on the order
until stock arrives, "Try to reserve the backorder" after a delivery lands);
delivering can be partial, prints a delivery note with a QR anyone can scan,
and is the moment stock moves; the invoice bills exactly what was delivered
and is reviewed and issued from Sales like any other; cancelling frees the
held stock and is refused once goods have gone (credit + return instead).

## DemoModulesSeeder scene sketch (a short order)

A distributor company with `orders` on: receive 6 Ciment 50kg via
`StockLedger::receive`, draft an order for 10, `confirm()` → the demo shows a
confirmed order with "4 backordered" on the line and the fulfilment board
reading promised 10 / shippable 6 / short 4, with the cement also sitting on
the replenishment screen (give it `reorder_level` so `replenishment_covers`
is true). Optionally deliver the 6 for a printable DLV note.

## What was built

| File | What it is |
|---|---|
| `database/migrations/2026_09_15_000201_create_sales_order_tables.php` | `sales_orders`, `sales_order_lines` (ordered/reserved/delivered/backordered/invoiced + price), `sales_order_invoices` link table. |
| `database/migrations/2026_09_15_000202_create_delivery_note_tables.php` | `delivery_notes` (own leased number, verification token, status for VOID watermark), `delivery_note_lines`. |
| `app/Models/SalesOrder.php`, `SalesOrderLine.php`, `DeliveryNote.php`, `DeliveryNoteLine.php` | BelongsToCompany + HasUlids throughout; `statusMark()` on the note. |
| `app/Services/Orders/OrderNumbers.php` | `DocumentNumbers` subclass: `SO-` / `DLV-` off the lease ledger. |
| `app/Services/Orders/Fulfilment.php` | `create`, `confirm` (reserve + named shortages), `deliver` (movements + note in one transaction, partial allowed), `invoice` (ordinary Document from delivered lines), `cancel` (release), `reserveBackorders`. |
| `app/Support/FulfilmentBoard.php` | Read model: promised / shippable today / short per open order, `replenishment_covers` read from `Replenishment`. |
| `app/Livewire/Orders/Index.php` + `Show.php` (+ blades) | House style; board tiles on the index; every service refusal surfaces via `addError`. |
| `app/Http/Controllers/Orders/DeliveryNotePrintController.php` + `resources/views/print/delivery-note.blade.php` | Print pipeline conventions of `print.document`: self-contained CSS, QR verify footer, status watermark, deliberately priceless lines. |
| `tests/Feature/Orders/FulfilmentTest.php` | 14 tests: reservation consumption, valuation agreement, one-generator invoice (issued through `DocumentIssuer` without a second movement), cancel, tenancy isolation, screens. |

Additive edits only to `app/Models/Item.php` (`salesOrderLines()`) and
`app/Models/Contact.php` (`salesOrders()`).

## Deliberate limits (small-business honest)

- Reservations are company-wide, not per location or lot; `deliver` moves
  from the order's location (or the ledger's default-null shelf). Picking a
  specific batch rides on a later phase via `BatchLedger`.
- No pricing engine: line price is keyed or the catalogue price; discounts
  belong on the invoice, where they already exist.
- Backorders do not auto-reserve when stock arrives — "Try to reserve the
  backorder" is a deliberate human act, like everything else that commits
  stock. A scheduler could call `reserveBackorders()` later.
- Cancelling after delivery is refused by design; the undo is a credit note
  and a return through the existing paths.
