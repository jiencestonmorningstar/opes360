# Customer orders and delivery

A shop sells over the counter: the invoice is the sale. A distributor
promises first and delivers later, and the distance between the promise and
the shelf is where the money gets lost — stock sold twice, shortages nobody
mentioned, invoices for goods that never travelled. This module closes that
distance: **draft → confirmed → delivered → invoiced**, with every step
honest about what actually exists.

## Drafting promises nothing

An order starts as a draft: a customer, lines, quantities, prices (keyed, or
the catalogue price). Nothing is held, nothing is promised. A draft can be
changed or thrown away without a trace on the shelf.

## Confirming reserves what exists — and names what does not

**Confirm** is the committing act. It holds real stock for the order through
the same reservations machinery the rest of the product uses — from that
moment, availability drops for everyone else.

What cannot be held is not quietly trimmed off the order. It becomes the
line's **backorder**: a named, visible figure — "4 of Ciment 50kg short" —
that stays on the order until stock arrives. When a delivery lands, press
**Try to reserve the backorder**; committing stock is a deliberate human act
here, so nothing reserves itself behind your back.

The fulfilment board reads each open order the same way: **promised /
shippable today / short**, and tells you whether the shortage is already
covered by a reorder on the replenishment screen.

## Delivering moves the stock

**Deliver** is the moment goods physically leave, and it is the moment stock
moves — the delivery note and the stock movements are written in one
transaction, so the paper and the shelf cannot disagree. Partial delivery is
normal: send what is held, the remainder stays reserved and the order stays
open.

Only what is reserved can go out. Delivering past the hold would ship stock
promised to somebody else — the exact failure reservations exist to prevent.

Every delivery note prints with a **QR code** that opens a verification page
on your own domain, so the person receiving the goods — or anyone holding the
paper months later — can check it is real. The printed lines carry
deliberately no prices: a delivery note is about quantities, and the driver's
copy is not a price list for the warehouse.

## The invoice bills what actually went

**Invoice** drafts an ordinary sales invoice from what has been **delivered
and not yet invoiced** — never from what was ordered. It appears in Sales as
a draft, is reviewed and issued there like every other invoice, and is chased
by the same dunning. Deliver in two trips and you can invoice each, or both
at once; nothing is ever billed twice.

## Cancelling gives the stock back — until goods have gone

Cancelling a confirmed order releases every reservation. Once anything has
been delivered, cancelling is refused: goods on a truck are not unwound by a
status change. The undo is a credit note and a recorded return, on paper,
like everything else.

## Who can do what

| Ability | What it allows |
|---|---|
| `orders.view` | See the orders, the board and the delivery notes |
| `orders.manage` | Draft orders, invoice deliveries, cancel |
| `orders.confirm` | Confirm an order — commit real stock to a customer |
| `orders.deliver` | Deliver — the moment goods leave and movements are written |

`confirm` and `deliver` are split out because they are the two acts that
touch the shelf: confirming drops availability for everyone else, and
delivering carries the same trust as posting a stocktake.

## Related

- [Reordering before you run out](/guides/replenishment) — the reorder levels
  the fulfilment board checks a shortage against.
- [Asking before buying](/guides/requisitions-and-quotes) — turning a
  shortage into a purchase.
- [Finding a document](/guides/documents-workspace) — where the drafted
  invoice lands and how it is issued.
