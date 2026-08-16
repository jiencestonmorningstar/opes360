# Reordering before you run out

The replenishment screen answers one question every morning: *what do I need to
order today so the shelf never goes empty?* Open it from **Products →
Replenishment**.

## What you set up, once per product

- **Reorder level** — the quantity at which it is time to order more. On the
  product form.
- **Maximum level** — optionally, how full the shelf should be. Suggestions top
  you up to this; without one, they top up to the reorder level.
- **A supplier, with their delivery time** — who you buy it from, roughly how
  many days they take, and what they last charged. Suppliers are your ordinary
  supplier contacts — there is no second supplier list to maintain.

The supplier link and the maximum level are edited right on the replenishment
screen — press **Edit supply** on a row. The row that says "No supplier on
file" is fixed where you are reading it.

## How to read the screen

A product appears when it has fallen below its reorder level — **or is selling
fast enough to get there before the supplier could deliver**. That second half
is the point of recording lead times: waiting until the level is crossed, when
the supplier needs a week to deliver, is planning to run out.

- **Red rows — "Will run out."** At the current rate of sale, the shelf goes
  empty before the supplier's delivery time is up. Deal with these first.
- **Amber rows — "Falling."** Above the reorder level today, but heading below
  it within the supplier's lead time.

The rate of sale is your last 30 days of actual sales. Transfers and count
corrections are not consumption, so moving stock between shops does not make
anything look popular.

## Accepting suggestions

Tick the rows you want and press **Raise requisitions**. What comes out is
**draft purchase requisitions, one per supplier** — everything you are ordering
from the same supplier lands on one requisition, because that is how you will
actually place the order, and the approver reads one ask per supplier rather
than a confetti of lines.

They are *drafts*, and that is deliberate: **nothing on this screen can spend
money.** A requisition raised here is identical to one typed by hand — it is
submitted from Procurement, approved (or not) through the same workflow as
every other request, and only then becomes an order. Seeing the list needs only
`products.view`; the button needs `procurement.requisition-manage`, the same
permission as raising any requisition, because it is the same act.

## Related

- [Asking before buying](/guides/requisitions-and-quotes) — where the drafts go
  next.
- [Making things](/guides/manufacturing) — if the stock you are keeping up is
  components.
