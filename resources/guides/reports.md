# Reports

Reports answer the questions a business asks out loud: how much did we sell,
who owes us, who should we chase today, and — on the executive view — is
anything on fire. Open them from **Reports**.

Every number on these screens is read from the records that produced it, over
a period you choose — this week, this month, this quarter, this year. Nothing
is typed into a report, so nothing on one can disagree with the invoices and
payments behind it.

## The sales report

The main reports screen is about selling and getting paid:

- **Revenue** — the total of invoices *issued* in the period.
- **Collected** — the payments actually *received* in the period.
- **Outstanding** — the balance still owed on unpaid invoices, right now.

Revenue and collected deliberately measure different things: an invoice
issued in March and paid in May counts toward March's revenue and May's
collections. If the two look far apart, that gap *is* the finding — it is
money promised but not yet in hand.

Below the cards: a chart of revenue over the period (bucketed by day, week or
month to match the range), a breakdown of how customers paid — cash, bank,
mobile money — and your top five customers by revenue.

The report exports as CSV: every invoice in the period with its customer,
status, total, what was paid and what is left.

## Ageing, statements and collections

Three more screens live under Reports, all about the gap between what is
promised and what is in the bank:

- **Ageing** — who owes us and who we owe, sorted by how late, both sides on
  one screen. Being owed four million matters differently when you owe three.
- **Statement** — one customer's account over a period, for the conversation
  where somebody disagrees about a balance. It defaults to the last three
  months, because the invoice in dispute is almost never this month's.
- **Collections** — who to chase, in order, and what was said last time.
  Calls are logged on the same screen as the queue, because a collector made
  to go elsewhere to log a call simply does not log it.

## The executive view

**Reports → Executive** is the one-page answer to "how are we doing, and what
needs eyes today". It shows the KPI cards with their change against the
previous period, then the alarms.

The page derives nothing of its own. Every figure is read through the report
that owns it, because a summary that disagrees with its detail teaches people
to trust neither. Where each number comes from:

- **Revenue and invoice count** — the same query the sales report runs, so
  the card and the report cannot drift apart.
- **Collected** — payments received in the period.
- **Expenses** — recorded bills in the period, voids excluded, tax included:
  "what did we spend" is what left the account.
- **Net result** — the ledger's own income statement, not revenue minus
  expenses. Those are different accounting bases, and only the books' answer
  is the result.
- **Average days to payment** — weighted by money, not by invoice count. Ten
  small invoices paid overnight and one large one paid at ninety days is a
  slow-paying book, and a simple average would call it fast.
- **Cash position, receivables outstanding and overdue, payables due soon** —
  balances as of right now. A balance never resets with the date filter, so
  these do not change when you switch the period; pretending they did is how
  a "last month" view ends up claiming money moved that didn't.

The page also lists customer profitability (revenue and collection per
customer — not margin, because cost is not recorded per invoice, and a
guessed margin is worse than none) and project budget consumption.

### The alarms

Underneath sit the things that need eyes today, each read from the screen
that owns it and each linking straight there: overdue receivables (to
ageing), compliance deadlines missed, contracts about to auto-renew, SLA
breaches on the service desk, and approvals that have stalled with no path
forward. A quiet alarm is shown as clear rather than hidden — an alarm panel
that hides its quiet alarms leaves you wondering whether they were checked at
all.

## Who can do what

| Ability | What it allows |
|---|---|
| `reports.view` | Open every screen above, executive view included |
| `reports.export` | Take the figures away as a CSV file |

Viewing and exporting are separate on purpose. Reading a report on screen and
walking out with the whole sales book as a file are different rights: a sales
officer or a read-only user can see the numbers without being able to carry
them off. Reports is also a module of its own, so a business that does not
want these screens can switch them off in Settings.

## Related

- [Selling and getting paid](/guides/sales-and-invoicing) — the invoices and
  payments every one of these numbers is read from.
- [Customers](/guides/customers) — the balances and statements, per customer.
- [The books, in plain words](/guides/accounting) — the ledger the net result
  comes from.
- [Deadlines and risks](/guides/compliance-and-risk) — the calendar behind
  the compliance alarm.
