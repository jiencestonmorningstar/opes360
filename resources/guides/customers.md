# Customers

The contact book is where everybody the business trades with lives — customers
and suppliers alike. Open it from **Customers**.

## The book

Each contact carries the details you would expect — name, company, phone,
email — plus payment terms, which decide the due date every new invoice gets.

A contact's page shows the whole relationship in one place: their recent
documents, their recent payments and the receipts behind them, and the notes
your team has left. Notes matter more than they look: "prefers to be called
after 4pm" is the difference between a collection call that works and one that
does not, and it should not live in one person's head.

## What a customer owes

Every contact carries a balance: what has been invoiced, less what has been
credited and paid. It moves the moment an invoice is issued, a payment is
recorded, a credit note lands or a document is voided — the figure is
recomputed from the documents themselves each time, so it can never drift from
what the paperwork actually says.

The customer's page shows the three figures that summarise the relationship:
billed to date, paid to date, and owing now.

## The statement

Press **Statement** on a customer to produce a statement of account: everything
that passed between you and them over a period, in date order, with the balance
running down the page.

The aging report answers "who do I chase". The statement answers the question
the customer asks back — *"for what?"* — and it is the document that settles a
disagreement between two sets of books.

Two decisions in how it is built are worth knowing, because they are the
difference between a statement that gets paid and one that gets argued with:

- **It opens with an opening balance** — everything owed from before the
  period, in one figure. A June statement sent to a customer who has owed money
  since March, with no opening balance, reads as a claim for June alone — and
  that is what gets paid.
- **Each line is the full amount charged or paid**, never a net figure. That is
  what lets a statement be produced for a past period and still be right about
  that period.

## Chasing what is overdue — collections

Open **Reports → Collections**. The aging report already says who is late; what
it cannot say is who to ring *first*, who was rung yesterday, and who promised
to pay on Friday and did not. Without that, the same three names get chased
twice a week and the rest are never chased at all. The collections queue is the
working list that fixes this.

### The order of the queue

Accounts appear in three bands:

1. **Broken promises.** Somebody gave a date and it passed. This is the
   strongest signal in collections and the cheapest call to make, because the
   conversation is already open.
2. **Everyone nobody has an arrangement with.**
3. **Open promises** — a date that has not arrived yet. Still owed, still
   visible, but ringing somebody on Thursday about a Friday promise is how you
   lose a customer you were about to be paid by.

Inside a band, accounts are ranked by the overdue amount **weighted by age**,
and the weighting is steep: money over 90 days late counts eight times what
money a fortnight late does. Old money outranks merely large money on purpose —
a million a week late will very likely arrive; two hundred thousand at 150 days
is the one about to become a bad debt.

Each row also shows the **net exposure**: the overdue debt after any credit
notes the customer already holds are set against it. That is what is genuinely
worth chasing — dunning a customer for money you have yourself written off is
how statements stop being trusted.

### Logging what happened

Log calls, emails and promises right on the queue, without leaving it. That is
deliberate: a collector who has to go and find the customer's page to record a
call simply does not record it — and an unlogged call is worse than no call,
because the next person rings the same customer about the same invoice.

A **promise to pay needs a date**. "They will pay" and "they said the 14th" are
different things: only the second can be broken, and broken promises are what
the queue sorts to the top. A customer who breaks a promise and then gives a
new date has an open promise again — the old conversation has already happened.

The header keeps score: accounts overdue, total exposure, broken promises, and
how many accounts have never been contacted at all. Filters cut the queue to
broken promises, untouched accounts, or anything over 90 days, and the whole
list exports to a spreadsheet.

## Who can do what

| Ability | What it allows |
|---|---|
| `customers.view` | Open the book, a customer's page and their statement |
| `customers.create` | Add contacts |
| `customers.update` | Edit them |
| `customers.delete` | Remove them |
| `reports.view` | Work the collections queue and log calls and promises |
| `reports.export` | Export the queue |

Collections sits on the reports abilities rather than on `customers.*` because
it is a report you act on: seeing the whole business's debt position is a
different trust from keeping the address book, and the person who edits contact
details is not always the person who should see who owes what.

## Related

- [Selling and getting paid](/guides/sales-and-invoicing) — the invoices,
  credit notes and payments the balance is made of.
- [Reports](/guides/reports) — the aging report the collections queue sits on
  top of.
- [Bringing your data in](/guides/imports) — loading your existing customer
  book from a spreadsheet.
