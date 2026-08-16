# Running a transport business

A transport business is a set of promises in motion: cargo somebody handed
you, a truck that has to come back, and a customer who wants to know where
their goods are without ringing the office. This module keeps those promises
on one board — and everything with a home elsewhere stays there: the truck is
an asset off your register, the freight invoice is an ordinary invoice, and
the proof of delivery signs through the same signature flow as every other
paper.

## Booking a shipment

Book cargo against a **sender** and a **receiver** from your customer book,
with the route, the cargo, its weight and the freight to charge. The shipment
gets its reference and — immediately, at the counter — its **tracking link**
to hand the customer. Not when the van leaves: the promise starts when the
booking does.

## The tracking page shows the shipment — and nothing else

Anyone with the link sees the reference, the route, the two party names on
that shipment, and its history: booked, loaded, in transit, delivered. That
is all. **Never** the manifest, the vehicle, the driver, the freight or
declared value, or any other customer's cargo on the same truck. A tracking
token is a window onto one shipment, not a door into your operation.

## The manifest: a truck, a driver, a day

Open a **manifest** with a vehicle from your asset register (it must have a
vehicle record — a plate) and a driver. Load booked shipments aboard; a
shipment can be aboard at most one open manifest, and the refusal names the
manifest it is already on.

Loading does **not** write a trip in the fleet log. Loading is warehouse
work — the van has not moved, and a trip is two odometer readings that do not
exist until it returns.

**Dispatch** is the committing act: the van leaves, and every shipment aboard
turns *in transit* on its tracking page in the same moment. A manifest that
left with half its cargo still reading "loaded" would be a lie on a public
page, so the two can never disagree.

## Delivery and the signed POD

Each shipment is delivered on arrival. Ask for a **proof of delivery** and a
POD paper is drafted and sent to the receiver for **e-signature through the
one signature flow** — the same signing tokens, the same verification page,
as every other document in the product. The shipment keeps a link to the
signed paper; there is no second signature machine.

## Closing the manifest writes the fleet log

When the van is back and everything aboard has arrived (or been struck),
**close** the manifest with the odometer readings. That writes **one trip**
into the fleet's own log — so the truck's mileage, consumption and
distance-based servicing see a dispatch journey like any other, with no
second mileage store to drift.

## The freight invoice

**Draft the invoice** and an ordinary sales invoice for the freight lands in
Sales, billed to the sender — reviewed and issued there, chased by dunning,
paid like anything else. Draft, not issued: freight gets renegotiated at the
counter, and a module that issued automatically would send arguments to
customers.

## Who can do what

| Ability | What it allows |
|---|---|
| `logistics.view` | The dispatch board and the shipment pages |
| `logistics.manage` | Book, open manifests, load, deliver, cancel, draft the invoice |
| `logistics.dispatch` | Dispatch a manifest — the committing act |

Dispatching commits the vehicle for the day and turns every booking aboard
into a promise on a public page; delivery and invoicing merely record what
then happened. That is why `dispatch` is split out and held high.

## Related

- [Where your equipment is](/guides/asset-movements) — the fleet: vehicle
  papers, trips, fuel, and servicing by distance.
- [Customer orders and delivery](/guides/sales-orders) — delivery notes for
  goods leaving your own stock, rather than cargo carried for others.
- [The service desk](/guides/service-desk) — the same pattern of promises
  watched on a board, applied to tickets.
