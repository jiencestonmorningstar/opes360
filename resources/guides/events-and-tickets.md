# Events and tickets

Sell tickets to an event through a public link, and scan them at the door.
The two halves are designed for the two days that matter: the weeks you are
selling, and the night you are checking people in with one hand on a phone.

Open it from **Events** in the menu, or press **New Event** among the quick
actions.

## Creating an event

An event is a title, a venue, a start (and optionally an end) time, and its
**ticket types** — Standard, VIP, Early Bird, whatever you sell — each with a
price and, if you want one, a capacity. Leave the quantity blank for a type
with no limit: "no limit" and "none left" are different answers, and the
system keeps them apart.

An event starts as a **draft**. Publishing it is a separate, deliberate step,
and it is refused until at least one ticket type exists — a published event
with nothing to buy is a broken link with your name on it. Once published, the
screen shows the share link and its QR code; that link is the sales page.

You can unpublish to pause sales, or **cancel** the event outright.

### Changing ticket types later

A type that has sold tickets can be renamed or repriced — future sales only,
because every sold ticket keeps the price it was actually sold at — but it can
never be deleted, and its capacity can never drop below what is already sold.
Deleting it would orphan tickets already in people's hands, and a capacity
below the sold count would make the "remaining" arithmetic go negative.

## Selling

Buyers open the public link, choose quantities, and leave a name and either a
phone number or an email — the tickets have to reach somebody. Each ticket is
issued individually with its own serial number and its own QR code, because
two seats bought together still admit two people separately.

Overselling cannot happen. The sale locks the ticket-type rows for the length
of the transaction, so two buyers racing for the last seat cannot both get it,
and an order that fails on its fourth ticket leaves nothing behind rather
than three strays.

**Sold is not paid.** A ticket records whether money arrived as a separate
fact, toggled on the event screen by whoever can see the list. Cash at a gate,
mobile money in advance and a promise from a friend are all real ways tickets
get paid for, and the system does not pretend to know which happened. The
event's revenue figure counts only tickets marked paid.

A ticket sold by mistake is **voided, not deleted**. The row, its serial and
its QR all stay, now answering "cancelled" — a serial that simply vanished
would make an honest buyer at the door indistinguishable from a forged one.

## The door

On the night, use the **Scan** quick action from the home screen. It opens
the camera; pointing it at a ticket's QR looks the code up and shows the
verification page. The code can also be typed or pasted — reading a code off
a printed page is a legitimate way to check one, not a fallback.

What a scan validates:

- **Genuine** — the QR resolves to a real ticket this system issued. A code
  nobody issued matches nothing.
- **The right event** — the page names the event and the ticket type, so a
  ticket from another night does not read as tonight's.
- **Not voided** — a cancelled ticket says so.
- **Fresh or already used** — this is the distinction a door actually needs.
  A checked-in ticket still reads as genuine, and the page carries its
  check-in state, because the question at the door is rarely "is this fake"
  and usually "has this already been through".

Checking in marks the ticket with the time and who scanned it. It can also be
done from the attendee list on the event screen, for the person working from
a printed list rather than a camera.

The verification page itself is public — anybody holding a ticket can check
their own — but **checking in requires being signed in** with the right
ability. A buyer cannot check themselves in from home.

## Who can do what

| Ability | What it allows |
|---|---|
| `events.view` | See events and their attendee lists |
| `events.create` | Create an event |
| `events.update` | Edit, publish, cancel, and mark tickets paid |
| `events.void` | Void a ticket |
| `events.check-in` | Check a ticket in at the door |

`check-in` is its own ability so door staff can be given exactly the job they
have: admitting people. Someone holding only `check-in` cannot sell, void or
reprice anything — a lost phone at a gate should not be a till.

## Related

- [Shareable forms](/guides/forms) — the other thing you share as a public
  link; useful for collecting registrations before tickets go on sale.
- [Selling and getting paid](/guides/sales-and-invoicing) — how money is
  recorded everywhere else in the product.
