# Products and stock

What you sell, what it costs you, how much of it is left and where it sits.
Open the catalogue from **Products**; the stock screens live under it.

## Items

An item is anything you sell — a product with stock behind it, or a service
with none. Each carries a selling price and a cost, and a product can carry a
**reorder level**: the quantity at which it counts as running low.

Stock is only tracked for items you ask it to be. A consultancy's "day of
work" has no shelf, and forcing a quantity onto it would make every report
about stock a little bit wrong.

## Stock moves by ledger, not by counter

A product's quantity on hand is never a number somebody edits. It is the sum
of movements: deliveries in, sales out, adjustments, transfers. Issuing an
invoice takes the goods off the shelf; voiding it puts them back — as a new
movement, not by deleting the old one. A counter can be quietly overwritten;
a ledger always has an answer to "why is it 40 and not 60".

## The stock value screen

**Products → Stock** shows two numbers side by side: what the shelves are
worth at weighted average cost, and what the books are carrying for stock.
They drift apart the moment anything is bought or sold, and the difference is
the screen's headline rather than a figure somebody has to work out — closing
that gap is what a count is for.

This is also where a **delivery arriving** is recorded: the items, quantities
and unit costs, the supplier, and optionally the expense that pays for it in
the same step, TVA included.

## Counting the shelves — stocktakes

Start a count from the stock screen. The count sheet is written for somebody
holding a phone in one hand and a crate in the other: one row per item, one
box to type into, nothing that has to be got right in order, and **Save** as
often as you like — you can carry on counting.

One rule matters more than all the others: **a blank box is not a zero.**
Blank means "not counted yet" and stays blank. The difference between an empty
shelf and a shelf nobody reached is the whole reliability of the count.

Posting the count turns every difference between counted and expected into a
stock adjustment, which is what brings the shelves and the books back into
agreement. A count posted in error can be voided.

## More than one place — locations and transfers

If the business keeps stock in more than one place, turn on the **Multiple
stock locations** module and open **Products → Locations**. A shop, a
warehouse and a van are three different answers to "how many have we got",
and a business running out at the counter while a case sits in the store room
is the problem this screen exists to make visible.

Add each place with a name and a kind. The first one becomes the **default
location** — where stock is attributed when nobody says otherwise — and you
can move the default later.

**Transfers** move stock between locations: from, to, a date, and the items
and quantities moved. A transfer changes where stock is, never how much of it
exists — the totals the sales and stock screens show are untouched.

A business with one till and one shelf never needs any of this, which is why
it is a module of its own rather than a screen everybody scrolls past.

## Knowing which lot — traceability

For products where "how many" is not enough — medicines, foodstuffs, anything
with an expiry or a serial number — switch the product's tracking on. It is
opt-in **per product**: a business selling t-shirts never meets it.

A tracked product's arrivals must name their lot (or serial) — an arrival
without one is refused rather than guessed at, because a product with one
named batch and a hundred untraced units *looks* like a complete record and is
not, and a recall run against it would report the wrong customers. The same
lot number arriving twice adds to the lot it already knows rather than
creating a twin, so a recall is never split in half.

Batches are depleted by movements, never by editing a quantity — the same
ledger discipline as everything else on this page.

Three abilities govern this, separately from the rest of stock: `track-view`
and `track-manage` for reading and recording lots, and `reserve` for holding
stock against a customer order before it ships (which is what the sales
orders module does when an order is confirmed). Recording which lot arrived is
a different job from correcting a count, and a pharmacy typically wants the
first devolved further than the second.

## Running low

Every product with a reorder level is watched, and what is running low can be
turned into draft requisitions with suppliers' lead times taken into account.
That has a screen and a guide of its own: [Reordering before you run
out](/guides/replenishment).

## Who can do what

| Ability | What it allows |
|---|---|
| `products.view` | See the catalogue, stock levels and the stock value screen |
| `products.create` | Add items |
| `products.update` | Edit items, prices and reorder levels |
| `products.delete` | Remove items |
| `products.adjust-stock` | Receive deliveries, save and post counts |
| `products.manage-locations` | Add locations and transfer stock between them |
| `products.track-view` | See lots, serials and expiry dates |
| `products.track-manage` | Record which lot arrived or left |
| `products.reserve` | Hold stock against an order |

`adjust-stock` is the one to grant carefully: it is the ability to say the
shelves hold something different from what the ledger says, and everything
downstream — the stock value, the books — believes it.

## Related

- [Reordering before you run out](/guides/replenishment) — reorder levels,
  lead times and draft requisitions.
- [Selling and getting paid](/guides/sales-and-invoicing) — the invoice that
  takes stock off the shelf.
- [Customer orders and delivery](/guides/sales-orders) — orders confirmed
  against real stock, and the reservations behind them.
- [Making things](/guides/manufacturing) — the production order that consumes
  components and receives finished goods.
