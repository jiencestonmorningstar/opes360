# Matching the bank

Reconciling is laying the bank's statement beside your books and pairing the
lines up, until every movement the bank reports is accounted for. It is the
cheapest audit a business can run on itself: the bank's record is the one
record nobody in the building can have typed wrong. Open it from **Banking**.

The module needs Accounting switched on, for a plain reason: reconciling means
reconciling *against the ledger*. Without the books there is nothing to match
a statement to.

## Setting up an account

Add each bank account and link it to the ledger account it mirrors — without
that link there is nothing to reconcile against. An account can also carry an
**opening balance** as at a chosen date. That line matters more than it looks:
without one, a business three years into trading could never reconcile,
because it would be asked to explain every movement since the day it opened
the account. Everything before the opening date is treated as settled; only
what comes after is open for matching.

## Importing a statement

Export a statement from your bank as a CSV and import it. The importer is
deliberately forgiving about column names — every bank exports a different
shape, in French or in English, and a business that has to rename headers in a
spreadsheet before it can import will do it once and then stop reconciling.
It needs at least a date column and either one amount column or a debit and
credit pair; separate debit and credit columns are folded into one signed
figure.

**Re-importing an overlapping period is harmless.** A line already present
with the same date, amount, reference and description is skipped, and the
screen tells you how many came in and how many were already there. Banks
rarely let you export "everything since last time", so overlap is the normal
case, not an accident.

## Matching

Each imported line starts **unmatched**. For the one you are working on, the
screen suggests candidate book entries — same amount, same direction, within a
few days, closest date first.

Suggestions are only ever suggestions; nothing is matched automatically. A
wrong automatic match is worse than no match: it looks reconciled, so nobody
ever looks again, and the two errors it papered over stay in the books for a
year. You confirm each pairing yourself, and a mistaken one can be unmatched.

One statement line pairs with one book entry — an entry already claimed by
another line is refused rather than shared.

## Lines the books have never seen

Some statement lines have no book entry to match, and never will until you
make one: bank charges, interest, standing orders — the movements nobody in
the business ever sees happen. For these, **record from the statement**: pick
the account the money belongs to and the entry is posted and matched in one
step. This is the genuinely useful half of a reconciliation — it is the only
way these movements ever reach your books.

A line that needs no entry at all — a duplicate, a bank's own reversal — can
be **ignored**, with a note saying why. Ignored is a decision on the record,
not a line quietly deleted.

## What "reconciled" means

The screen shows the arithmetic rather than a verdict: the book balance, the
statement balance, and the unmatched movements on each side that explain the
difference between them. A green tick over three unexplained lines would not
be a reconciliation, it would be a hiding place.

A finished reconciliation asserts something precise: **every line the bank
sent has been matched, recorded or knowingly ignored, and the remaining
difference between the bank and the books is zero.**

It deliberately does *not* require the other direction to be empty. A cheque
you have written that the payee has not banked yet is a normal reconciling
item, not an error — a reconciliation that refused to finish until the payee
went to the bank would never finish. That figure is reported separately, so it
stays visible instead of being quietly absorbed.

Whatever difference remains after all of that is a real discrepancy, and the
whole point of the exercise is that it is now small enough to go and find.

## Who can do what

| Ability | What it allows |
|---|---|
| `banking.view` | Open the screen and see where the reconciliation stands |
| `banking.manage` | Add bank accounts and link them to the ledger |
| `banking.import` | Import statement files |
| `banking.reconcile` | Match, unmatch, record from the statement, ignore |

`reconcile` is separate from `import` because they are different acts: loading
what the bank says changes nothing, while matching a line — or recording one
straight into the books — is a statement that two records describe the same
money, and that assertion should carry a name.

## Related

- [The books, in plain words](/guides/accounting) — the ledger the statement
  is matched against.
- [Selling and getting paid](/guides/sales-and-invoicing) — the payments that
  become the deposits on the statement.
- [Deciding which bills to pay](/guides/paying-suppliers) — the payment runs
  that become the withdrawals.
