# Selling and getting paid

Everything the business puts in front of a customer — the offer, the bill, the
proof of payment, the correction — lives on one screen. Open it from **Sales**.

The list is split into tabs, one per kind of paper: quotations, proformas,
invoices, credit notes and the rest. It lands on the tab that actually has
something in it, and the chips above the list filter by the question you are
really asking — paid, pending, overdue, or still a draft.

## The papers, and what each one commits you to

- **A quotation** is an offer. It can be issued all day without a franc moving.
- **A proforma** looks like an invoice but is not one: nothing is owed on it.
  Some customers need it to raise their own paperwork before they will accept
  the real thing.
- **An invoice** is the bill. Issuing it is the moment the customer owes you —
  it enters the books, comes off the shelf, and lands on the customer's balance.
- **A receipt** is proof that money changed hands. You never create one; it is
  issued automatically when a payment is recorded.
- **A credit note** is the one paper that moves money the other way: it cancels
  part or all of an invoice the customer already holds.

## Draft, then issued

A document starts as a draft. A draft can be edited freely and commits you to
nothing — no number, no stock movement, no entry in the books.

**Issuing** is the moment it becomes real, and immutable. In one step it gets
its final number, its dates are frozen, the sale enters the books, the goods
leave the shelf, and a verification QR is minted so anybody scanning the
printed copy can confirm it is genuine. There is deliberately no way to edit
an issued document: the customer is holding a copy, and your copy must never
quietly stop matching theirs.

If an issued document is wrong, you do not edit it — you **void** it or
**credit** it. See below.

## From quotation to invoice

Press **Convert** on an accepted quotation (or proforma, or finished work
order) and it becomes a draft invoice with the same lines. The source is
copied, never changed: the quotation stays exactly as the customer saw it, and
the invoice records which quotation it came from. A quotation that has already
produced an invoice cannot produce a second one.

## Recording a payment

On an issued invoice, record what the customer paid — amount, method,
reference. In one step the payment is saved, the invoice's balance comes down,
its status moves to partly or fully paid, and a numbered receipt is issued with
its own verification QR.

Two refusals worth knowing:

- **Draft and voided documents take no payments.** Nothing is owed on either.
- **Overpayment is refused.** A payment larger than the outstanding balance is
  an error to surface, not credit to absorb silently.

## Credit notes

Press **Convert** on an invoice to credit it **in full** — the credit note is a
line-for-line mirror of the invoice, so the customer recognises what is being
cancelled.

To credit **part** of an invoice, name the amount instead. A partial credit is
a single line saying what it is, because there is no honest way to spread
15 000 F across seven lines without inventing which of them the customer was
overcharged on. The TVA is split out at the invoice's own rate, so the tax you
reclaim is exactly the tax you charged.

An invoice can be credited until it has been credited in full, and not a franc
further — the screen tells you how much is left.

## Voiding

Voiding is the only way to undo an issued document. The document is marked
void, the sale is reversed out of the books, the goods go back on the shelf,
and the QR on the printed copy starts answering "Voided" instead of "valid".

The number is retired, never reused — see numbering below.

A document with payments against it **cannot be voided**. Refund or reallocate
the money first; voiding a paper that cash has been taken against would leave
the payment pointing at nothing.

## How numbers work

Numbers look like `INV-2026-00042`: a prefix per document type, the year, and a
sequence. Receipts have their own series (`RCP-…`).

Every number ever allocated — used, unused or voided — has a row in a ledger,
which is what makes a gap in the sequence defensible to a tax authority: a
missing number is always either a voided document or a recorded unused block,
never a mystery.

That ledger is also how selling works with no connection. A device leases a
block of numbers in advance and puts them on paper offline; when it syncs, each
number is honoured only if it falls inside a lease that device actually holds.
A device cannot invent its own sequence, and a number a customer is already
holding is never silently replaced.

## Who can do what

| Ability | What it allows |
|---|---|
| `sales.view` | See the sales list and open documents |
| `sales.create` | Draft quotations, invoices and the rest |
| `sales.update` | Edit drafts |
| `sales.issue` | Turn a draft into the real thing |
| `sales.void` | Cancel an issued document |
| `receipts.view` | See and reprint receipts |
| `payments.view` | See payments |
| `payments.record` | Take a customer's money |
| `payments.refund` | Give it back |

`issue` is separate from `create` on purpose: drafting a bill costs nothing,
issuing one puts money on a customer's account. And `payments.record` is money
coming *in* — a cashier who may take a customer's money has no business
recording what the company spends, which is why expenses are a different group
entirely.

## Related

- [Customers](/guides/customers) — the balance every invoice and payment moves,
  and chasing what is overdue.
- [Products and stock](/guides/products-and-stock) — what issuing an invoice
  takes off the shelf.
- [The books, in plain words](/guides/accounting) — where the sale lands when
  it is issued.
