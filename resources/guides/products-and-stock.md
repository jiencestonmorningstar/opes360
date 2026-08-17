# Products and stock

The product list is what the invoice composer picks from: what you sell, what
it costs you, and what you charge for it. Stock is what the business still has
of it, and everything about stock here rests on one decision: **quantities are
never stored, they are added up**.

Open the list from **Products**; the stock screens live under it.

## Items

An item is a **product** or a **service**. Both carry a name, a selling price
(required — zero is fine), a cost, a unit, an SKU and a barcode. A product can
additionally have its stock tracked; a service has nothing to count.

Tracking is a checkbox per item on purpose. A business that sells consulting
next to cables should not have to invent quantities for the consulting, and no
stock check ever blocks an untracked item — blocking on a number that means
nothing is worse than not blocking.

Opening stock, typed when the item is created, is recorded as the **first
movement in the ledger**, valued at the cost typed alongside it — not written
into a column. The ledger starts there and everything after appends to it.
That is what lets two devices sell the last unit offline and be reconciled
afterwards instead of overwriting each other.

## Adjustments and deliveries

A correction — breakage, theft, a miscount — is an adjustment movement with a
reason, made by somebody holding `products.adjust-stock`. Seeing stock and
correcting it are different trusts, which is why they are different abilities.

A delivery arriving is recorded on the stock screen too: supplier, date,
reference, lines with quantities and unit costs, and optionally the expense
that pays for it in the same breath.

## Counting: stocktakes

**Products → Stock** shows two numbers side by side: what the shelves are
worth at weighted average cost, and what the books are carrying for inventory.
They drift apart the moment anything is bought or sold, and closing that gap
is what a count is for — so the difference is the screen's headline rather
than a figure somebody has to work out.

Starting a count opens a sheet of every tracked product with what the ledger
claims. The book quantity is **frozen at that moment**, not read live at
posting: a count walked over an afternoon during which the shop kept selling
should be compared against the shelf as it was when the counting started.

Posting the count does two things in one transaction: an adjustment movement
for every line that disagreed, so stock on hand afterwards is what was
actually counted, and the value change posted to the books. Both or neither —
a count that moved the stock without moving the books is worse than no count.

A line nobody counted is **left alone**. Blank is not zero, and treating it as
zero would write off every item the counter did not reach before closing time.

## Multiple locations

With the **Multiple stock locations** module on (it requires Products), a
shop, a warehouse and a van each hold their own stock, and goods move between
them as **transfers** under **Products → Locations**.

A transfer refuses to move more than is at the source, refuses to move stock
to where it already is, and writes its two halves — out of one place, into the
other — in one transaction. A transfer that took stock out and never put it in
would be worse than one that never happened, because the difference shows up
as a shortage somebody spends an afternoon looking for.

The module exists separately because a business with one till and one shelf
does not need locations, and turning them on should not be a decision it has
to understand before it can record a sale.

## Traceability: lots, serials, expiry

Opt-in per product, so a business selling t-shirts never meets it. A traced
product's arrivals must name their lot — the system refuses to invent one,
because a product with one batch and a hundred untraced units *looks* like a
complete record and is not, and a recall run against it would report the wrong
customers. The same lot number arriving twice adds to the lot it already knows
rather than creating a twin, for the same reason: two rows called LOT-A would
split a recall in half and each half would look complete.

Batches deplete by movements written against them, never by decrementing a
column — the same append-only rule as everything else in stock.

Recording which lot arrived is a different job from correcting a count, so
traceability has its own abilities (`products.track-view`,
`products.track-manage`) rather than riding on `products.adjust-stock` — a
pharmacy typically wants the first devolved further than the second.

## Reservations

Stock can be **promised** before it leaves the shelf — a confirmed sales
order, a picking list. Promising is deliberately not a movement: stock on hand
must keep agreeing with what a person counts on the shelf, so only *available*
drops, where available = on hand − live reservations. Promising the same unit
twice is refused; the failure mode is two customers each told their order is
ready and one of them finding out at the counter. A business that never
reserves anything sees available equal on-hand forever.

## Replenishment

**Stock → Replenishment** watches reorder levels and says what needs buying
before the shelf is empty. It has its own guide:
[Replenishment](/guides/replenishment).

## Who can do what

| Ability | What it allows |
|---|---|
| `products.view` | See the list, the stock screen and its counts |
| `products.create` | Add items |
| `products.update` | Edit items and their prices |
| `products.delete` | Remove one |
| `products.adjust-stock` | Corrections, deliveries, posting counts |
| `products.manage-locations` | Locations and transfers between them |
| `products.track-view` | See lots, serials and expiry |
| `products.track-manage` | Record which lot arrived or went out |
| `products.reserve` | Promise stock without moving it |

## Related

- [Selling and getting paid](/guides/sales-and-invoicing) — issuing the
  invoice is what takes goods off the shelf.
- [Replenishment](/guides/replenishment) — knowing what to buy before it runs
  out.
- [Sales orders and delivery](/guides/sales-orders) — confirming an order is
  what reserves the stock.
- [Manufacturing](/guides/manufacturing) — production consumes parts and
  receives product through the same ledger.
- [Closing the books](/guides/closing-the-books) — how the count reaches the
  income statement.
