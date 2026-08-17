# Customers

The contact book is where every other part of the product looks up who it is
dealing with: an invoice points at a customer, a deal points at one, a ticket,
a tenancy, a policy. Keep it clean and everything downstream reads well.

Open it from **Customers**.

## One customer, one page

A customer's page gathers what the rest of the system knows about them: their
recent documents, their recent payments and receipts, how much they have been
billed, how much they have paid, and what they still owe. Notes live here too —
short, dated, signed with who wrote them — so the next person picking up the
phone knows what was said last time.

## What a customer owes

The figure the customer list sorts by is computed from the documents
themselves: every issued invoice's open balance, less every issued credit note.
It is never nudged up and down as events happen — it is recomputed from truth
each time something changes on the account. That makes it self-healing: a
missed update leaves the figure stale for a moment, not permanently wrong.

Credit notes subtract because an issued credit note is money the business has
said in writing it is no longer owed.

## Statements

A statement of account is everything that passed between the business and one
customer over a period, in date order, with the balance running down the page.
The aging report answers "who do I chase"; the statement answers the question
the customer asks back — *for what?* — and it is the document that settles a
disagreement between two sets of books.

Two design decisions worth knowing:

- Each line is what was **charged** or what was **paid**, never a document's
  remaining balance. Balances would net the payment into the invoice line and
  then count it again as its own line — and a balance only knows about today,
  where a statement can be produced for a past period and still be right
  about it.
- The **opening balance** summarises everything before the period into one
  figure. Leave it out and a June statement sent to a customer who has owed
  money since March reads as a claim for June alone — and that is what gets
  paid.

Print one from the customer's page, or run statements from **Reports**.

## Collections: who to chase, in what order

**Reports → Collections** is the working queue that sits on top of the aging
report. The aging report says who is late; it cannot say who to ring first, who
was rung yesterday, and who promised to pay on Friday and did not — so the same
three names get chased twice a week and the rest are never chased at all.

The queue orders every overdue account in three bands:

1. **Broken promises** — somebody gave a date and it passed. The strongest
   signal in collections and the cheapest call to make, because the
   conversation is already open.
2. **Everyone nobody has an arrangement with.**
3. **Open promises** — a date that has not arrived yet. Still owed, still
   visible, but ringing somebody on Thursday about a Friday promise is how a
   business loses a customer it was about to be paid by.

Inside a band, accounts are scored by overdue amount **weighted by age**, and
the weighting is steep on purpose: old money outranks merely large money. A
million a week late will very likely arrive; two hundred thousand at 150 days
is the one about to become a bad debt.

Each row also shows the customer's **net exposure** — the overdue debt after
any credit the customer already holds is set against it — which is what is
genuinely worth chasing. Credit notes are never counted as receivables; a
business must not chase a customer for money it has itself written off.

### Logging what happened

Log the call, the email, the visit or the promise on the same screen, without
leaving the queue. That is deliberate: a collector who has to go and find a
notes tab simply does not record the call, and an unlogged call is worse than
no call, because the next person rings the same customer about the same
invoice.

A promise **requires a date**. "They will pay" and "they said the 14th" are
different things, and only the second can be broken — which is exactly what
the queue sorts on. Nothing about the queue itself is stored; like the aging
report it is a photograph of the moment, and the only thing worth persisting
is what a human did about it.

Filters cover broken promises, accounts never contacted, and debt over 90
days, and the whole queue exports to CSV.

### Automatic reminders

Separately from the human queue, the business can switch on **dunning**: a
ladder of reminders at fixed days past due (7, 30 and 60 by default). Each
step sends exactly once per invoice — the record of sends is the mechanism,
not a log kept for interest — and tiny balances are not chased at all, because
a reminder about six hundred francs costs more than it recovers. It is off
unless a company turns it on: these messages go out under the business's name,
about invoices that may have been settled in cash nobody recorded yet.

## Who can do what

| Ability | What it allows |
|---|---|
| `customers.view` | Open the book and a customer's page |
| `customers.create` | Add a customer |
| `customers.update` | Edit a customer's details |
| `customers.delete` | Remove one |
| `reports.view` | Open the collections queue and log activity on it |
| `reports.export` | Export the queue to CSV |

Collections sits on the reports abilities rather than its own, because the
queue is a report with a pen: reading it and writing down what was said are
the same job, done by the same person, in the same sitting.

## Related

- [Selling and getting paid](/guides/sales-and-invoicing) — the invoices and
  payments a customer's balance is made of.
- [Leads and deals](/guides/leads) — before somebody is a customer.
- [Reports](/guides/reports) — the aging report the collections queue sits on.
- [VIP membership](/guides/vip) — tiers and benefits for the customers worth
  keeping closest.
