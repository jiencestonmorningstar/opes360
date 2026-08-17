# Matching the bank

Reconciliation is laying the bank's statement beside the books and pairing
every line up. It is the one check that catches everything else: a payment
recorded twice, a receipt never recorded, a bank charge nobody in the business
ever saw happen.

Open it from **Banking**. It needs the accounting module, because reconciling
means reconciling *against the ledger* — without the books there is nothing to
match a statement to.

## Setting up an account

Add each real bank account once, linked to the ledger account that represents
it in the books, with an opening balance and the date it was drawn. Everything
before that date is treated as settled — re-litigating history is what makes a
first reconciliation impossible.

## Importing a statement

Import the bank's CSV export. The parser is deliberately forgiving about
column names — every bank exports a different shape, in French or English,
with one amount column or a debit/credit pair, and a business that has to
rename headers in a spreadsheet before it can import will do it once and then
stop reconciling. Dates in any common order and amounts written any way —
"1 250 000,50", "1,250,000.50", "(1250)" — are all understood.

Re-importing an overlapping period is harmless: a line already present with
the same date, amount, reference and description is skipped. Banks rarely let
you export "everything since last time", so overlap is the normal case, not an
accident. A line can also be added by hand for the statement a bank only
issues on paper.

## How matching works

Every statement line starts **unmatched**. For each one the screen proposes
candidate entries from the books — same amount, same direction, within a few
days, closest date first — and you confirm the pair.

Suggestions are never applied automatically, and that is a design decision,
not a missing feature. A wrong automatic match is worse than no match: it
looks reconciled, so nobody ever looks again, and the two errors it papered
over stay in the books for a year.

The direction check matters more than it looks: money into the bank is a debit
in the books, money out is a credit, and a "match" whose signs disagree is two
unrelated movements. An entry already claimed by one statement line cannot be
matched to a second, and a mistaken match can always be undone.

## What an unmatched line means

An unmatched line is one of three things:

- **The books are behind.** The event happened and nobody recorded it. Match
  it to the entry once the entry exists.
- **Nobody in the business ever saw it.** Bank charges, interest, standing
  orders. These reach the books *only* through reconciliation — use **record
  from statement** to post the entry (naming the counter-account) and match
  the line in one step. If that posting fails, it fails loudly and the line
  stays unmatched; a line silently marked matched against nothing would be
  money invisible to the books forever.
- **It needs no entry at all** — a duplicate, a bank-side reversal. Mark it
  **ignored**, with a note saying why.

The other direction exists too: an entry in the books the bank has not shown
yet — the classic is a cheque written but not yet presented. That is a normal
reconciling item, not an error, and it is reported as its own figure rather
than blocking anything.

## What a completed reconciliation asserts

The screen shows arithmetic rather than a verdict: the book balance, the
statement balance, and the unmatched movements on each side that explain the
gap. The books, adjusted for everything the bank has recorded that we have
not, less everything we have recorded that the bank has not, should equal the
statement — and whatever is left over is a real discrepancy, shown as the
difference.

**Reconciled** means every line the bank sent has been accounted for — matched
or deliberately ignored — and that difference is zero. It deliberately does
*not* require the unpresented-cheque figure to be zero: a month that refused
to close until every payee banked their cheque would never close. That figure
stays visible instead of being quietly absorbed, because "reconciled" with
three unexplained lines is not reconciled, it is hidden.

## Who can do what

| Ability | What it allows |
|---|---|
| `banking.view` | Open the screen and see where things stand |
| `banking.manage` | Add and edit bank accounts |
| `banking.import` | Import statements |
| `banking.reconcile` | Match, unmatch, ignore, and record from statement |

Importing and reconciling are separate abilities because they are different
acts: loading what the bank says changes nothing, while a match — or an
ignore — is a judgement that two records are the same event, and it is the
judgement an auditor will ask about.

## Related

- [Closing the books](/guides/closing-the-books) — reconciliation is the check
  a month-end leans on.
- [Accounting](/guides/accounting) — the ledger the statement is matched
  against.
- [Selling and getting paid](/guides/sales-and-invoicing) — the receipts that
  should appear on the statement.
- [Paying suppliers](/guides/paying-suppliers) — the payments that should
  appear on the other side.
