# Letting a property

Letting looks like a business of buildings, but it is really a business of
obligations: a lease with a notice date, rent that must arrive every month,
and a deposit that is somebody else's money in your keeping. This module adds
only the geography — properties, units, tenancies. Everything with money or
dates in it runs through the machinery the rest of the product already
trusts: **a lease is a contract, rent is a recurring invoice, a deposit is a
posted liability, a maintenance request is a service ticket.**

## The building and its doors

Add a property (with its landlord from the contact book, if you manage for
one) and its units, each with an **asking rent** — the figure a vacancy is
measured against, not what any tenant actually pays.

## Moving a tenant in

One act creates everything, together or not at all:

- **The lease is a real contract**, raised on the contract register with the
  tenant as counterparty and the rent as its value. Renewal, the notice date
  and the watch all come from the contracts module — a lease that renews
  itself past an unwatched date is the same trap as any other contract, and
  the same screen catches it.
- **The rent bills itself**: an ordinary monthly recurring invoice, issued by
  the nightly generator and chased by dunning, which knows nothing about
  property.
- **The deposit goes into the books the day it is taken** — posted to
  account **165 « Dépôts et cautionnements reçus »**, a liability, because a
  caution is the tenant's money in your keeping, not income and not an
  advance against invoices. Never a memo column pretending to be money.

One open tenancy per door: moving somebody into an occupied unit is refused,
naming the sitting tenant.

## Rent, arrears and maintenance

Overdue rent is chased like any other invoice, and the occupancy board's
arrears figure is **the same figure the aging report shows** — one source,
never a private copy that could disagree.

A fault reported by a tenant opens an ordinary **service ticket** (category
maintenance), pinned to the unit — same desk, same clock, same board as every
other job.

## Reviewing the rent

Rent changes from a date, never backwards. A review keeps the history (what
it was, what it became, from when, and why), updates the tenancy, and
rewrites the recurring schedule's line so **the next bill carries the new
figure** — invoices already issued stand exactly as they were. A change dated
beyond the next billing run is refused rather than applied early: record it
when its period is next to bill.

## The landlord statement

If you manage the building for an owner, the property page keeps their
account: **rent collected** on their units (collected, not billed — the
landlord is owed what actually came in), minus your **commission** at the
property's percentage, minus **expenses recorded against the property** (a
repair, a guard, a water bill you covered), minus **payouts already made**.
The closing balance is what the landlord is owed — the page you lay beside
the landlord's own arithmetic before anybody argues.

Paying them out turns that figure into an ordinary **payable**: an expense to
the landlord contact (category rent, account 622), settled from the expenses
screen like any other bill, and shown on the next statement as a payout line.
Paying more than the statement says is owed is refused — handing a landlord
money you have not collected is lending, and that is not this button.

## Inspections

A move-in or move-out inspection is a **managed paper** — use a checklist
document from the documents workspace and link it to the tenancy (or the
property); it appears in the Papers panel with its signatures and versions,
no separate inspection machinery to learn.

## Moving out: the deposit answered for

Ending a tenancy settles everything in one act:

- **Unpaid rent blocks the move-out**, naming the tenant and the balance. It
  can be forced — tenants do leave owing money — but only with a written
  reason, because that reason is the only record of why the money was let go.
- **Retaining any part of the deposit needs a reason.** It is the tenant's
  money until a reason says otherwise. The retained part becomes income
  (7078, accessory income of the letting) **at settlement only**; the rest is
  refunded from the bank. One journal entry carries all three legs, so the
  books can never catch the settlement half-done.
- The lease terminates through the ordinary contract lifecycle, the rent
  schedule is marked finished (kept, as the record of what was billed), and
  the unit goes back to vacant.

## Who can do what

| Ability | What it allows |
|---|---|
| `estate.view` | The occupancy board and the properties |
| `estate.manage` | Add properties and units, move tenants in, review rents, record property expenses, log maintenance |
| `estate.end-tenancy` | End a tenancy, and pay a landlord out — the acts that move money |

Ending a tenancy is the one money-committing act: it decides how much of the
caution the business keeps and pays the rest out of the bank, and it can
force past unpaid rent. That is why it is split out and held high.

## Related

- [Contracts](/guides/contracts) — the lease's register, its notice date, and
  the watch that keeps it honest.
- [The service desk](/guides/service-desk) — where a tenant's maintenance
  request lives once reported.
- [Closing the books](/guides/closing-the-books) — where the deposit
  liability and the retention income sit when the month is closed.
