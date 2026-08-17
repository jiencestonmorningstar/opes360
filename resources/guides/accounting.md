# The books, in plain words

Every sale, payment, expense and payroll run in this system ends up in one
place: the books. This guide explains what that place is, in the words a
business owner uses rather than the ones an accountant does. You do not need
any of it to run your business day to day — that is rather the point — but on
the day your accountant, your bank or the tax office asks a question, this is
where the answer lives.

Open it from **Accounting**.

## The chart of accounts

The chart is a numbered list of boxes, one for each kind of thing the business
has or does: cash, the bank, what customers owe, what you owe suppliers, sales,
rent, salaries. Every franc that moves is filed into one of these boxes.

The numbering follows **SYSCOHADA**, the accounting plan used across the OHADA
countries. The first digit is the class: 1 is capital, 2 is what you own
long-term, 3 is stock, 4 is what people owe and are owed, 5 is cash and bank,
6 is what things cost you, 7 is what you earned, 8 is the exceptional rest. So
`411` is customers, `401` is suppliers, `571` is the till, `601` is goods
bought for resale. Your accountant already knows these numbers by heart, which
is exactly why the system uses them: what you hand over needs no translation.

The **Plan comptable** tab shows your chart. A starter chart is seeded for you,
and one-click suggestions offer the standard subdivisions when you want finer
boxes. You can add an account (2–8 digits, starting with its class), rename
one, or deactivate one you have stopped using. An account with even one entry
in it can never be deleted — it is part of the record — only deactivated.

## You never post anything

The important thing about the books here is that **you do not write them**.
Everyday actions post themselves:

- **An invoice** records a sale and adds what the customer owes.
- **A payment** moves that debt into cash or the bank.
- **An expense** charges the cost where it belongs — the category you pick on
  the expense form is a SYSCOHADA account wearing a plain-English name.
- **A payroll run**, when approved, posts the whole month's wages, charges and
  taxes in one entry.

There is no "new journal entry" button on the main screens, deliberately. A
hand-typed entry is where books go wrong; an entry derived from a real document
can always be traced back to it.

## Journals

A journal is a diary: entries in date order, each one saying what moved and
why. Entries are grouped into five books, the way an accountant expects:

| Code | Name | What lands in it |
|---|---|---|
| VE | Ventes | Sales |
| AC | Achats | Purchases and expenses |
| BQ | Banque | Money through the bank |
| CA | Caisse | Money through the till |
| OD | Opérations diverses | Everything else — payroll, adjustments |

The **Journal** tab shows them, filterable by book and by period. Every entry
balances: what went in one box came out of another, always, which is the whole
discipline of double-entry and the reason totals can be trusted.

## Ledgers and the trial balance

The **Grand livre** (ledger) is the same entries re-sorted by account instead
of by date: pick an account and read its story — every movement, running to a
balance. It answers "what happened in this box?"

The **Balance générale** (trial balance) is one line per account: total debits,
total credits, balance. Its two grand totals always agree — if every entry
balances, the whole must — and it is the page your accountant will ask for
first. Press **Export** to hand it over as a file; that needs the separate
`accounting.export` ability, because reading a report on screen and walking out
with the whole ledger are different rights.

## The statements

Two tabs turn the same figures into the documents outsiders ask about:

- **Compte de résultat** (income statement) — what you earned against what it
  cost you, over a period. The difference is profit, and it is worked out from
  classes 6 and 7, not typed anywhere.
- **Bilan** (balance sheet) — what the business owns and owes *on a day*: a
  photograph, where the income statement is a film.

**Cash flow** is the third question — profit is not cash, and a profitable
business can still run out of money. Where the cash went, along with financial
years, closing a month and cost centres, is covered in
[Closing the books](/guides/closing-the-books).

## Periods

The **Exercices** tab holds financial years, each created with its twelve
months. Closing a month makes the books refuse any new posting dated inside it
— which is how a figure you have already declared stays the figure you
declared. Reopening is possible, but it is a deliberate act by someone with
`accounting.manage`, not something that happens by accident. The full story is
in [Closing the books](/guides/closing-the-books).

## The tax worksheet

**Accounting → Declarations** works the month's returns out from the books —
including the TVA collected on sales and the TVA you can reclaim on purchases —
as a worksheet to copy onto the official forms, with the entries behind every
figure. It defaults to last month, because last month is the one actually due.

## Who can do what

| Ability | What it allows |
|---|---|
| `accounting.view` | Open the books: balance, ledgers, journal, statements, declarations |
| `accounting.export` | Take the trial balance away as a file |
| `accounting.manage` | Edit the chart, and open and close periods and years |

`export` is separate from `view` because a file leaves the building. `manage`
is separate from both because whoever can close a period, or add an account,
is shaping the record everyone else writes into.

## Related

- [Closing the books](/guides/closing-the-books) — years, periods, cost
  centres, and where the cash went.
- [Selling and getting paid](/guides/sales-and-invoicing) — the documents that
  post the sales side.
- [Spending money](/guides/expenses) — the documents that post the cost side.
- [Matching the bank](/guides/banking-reconciliation) — proving the books
  against the bank's version of events.
