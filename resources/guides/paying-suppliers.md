# Deciding which bills to pay

Most businesses do not run out of money. They run out of money *on a Tuesday*,
having paid the wrong bills on the Monday. This is the screen for deciding which
bills go out this week, against the cash you actually have.

Turn it on under **Settings → Modules → Payment scheduling**. It ships off,
because a shop that pays its supplier when the supplier turns up has nothing to
schedule.

## The schedule

Every unpaid bill is put into one of three bands:

- **Overdue** — past its due date,
- **Due soon** — inside the next week,
- **Later** — everything else.

Within a band, bills are ordered by amount weighted by how late they are. The
weighting is gentle — a bill three months old counts about three times a fresh
one, not eight. A supplier's patience does not fall off a cliff the way a
customer's willingness to pay does, and a steep curve would starve every recent
bill in order to clear one ancient dispute.

You then tell it how much cash is available, and it walks the list marking each
bill **fund**, **part** or **defer**.

### Reserve

**Reserve** is cash the plan may not touch. It is the only thing standing
between a heavy payables week and payroll. Set it before you set anything else.

### Where the cash figure comes from

The schedule takes only the *receipts* side of the cash forecast — money coming
in. It deliberately ignores the forecast's payments side, because those payments
*are* the bills being decided here. Counting them would deduct every bill twice
and convince a business that could pay that it could not.

## Payment runs

A schedule becomes a **payment run**, which has three states:

1. **Draft** — you are still building it. Add and skip lines freely.
2. **Approved** — somebody has signed it off.
3. **Executed** — the money has gone.

A run that is not approved **cannot be executed**. That refusal is the only
thing between a misclick and an emptied bank account, so the two abilities are
granted separately: whoever builds the run should not be the person who releases
it. If your business is small enough that this is the same person, they should
still have to press two buttons on two occasions.

If somebody settles a bill by hand between approval and execution, that line is
skipped with a reason rather than failing the whole batch.

## Supplier statements

A supplier's statement is their account of what you owe them. Yours is in the
books. When the two disagree, that disagreement is the product.

Import a statement (CSV) under **Reconcile**. Re-importing the same file is safe
— lines are fingerprinted, so nothing doubles up.

Matching tells the system that one of their lines and one of your bills describe
the same event. It never writes to your expenses, and it never recalculates the
supplier's own closing balance from their own lines. If their total disagrees
with their own detail, that is a finding worth putting in front of them, and
quietly correcting it erases it.

**Automatic matching is conservative on purpose.** A line matches only when the
reference *and* the amount agree *and* exactly one candidate exists. The right
reference with the wrong money is a dispute, not a match. Two candidates for one
line is the duplicate-billing case, and it is exactly what a human needs to see.

The summary shows their balance, your balance, and the difference explained by:

- **on their statement only** — they think you owe something you have not recorded,
- **in our books only** — you have recorded something they have not billed.

## Who can do what

| Ability | What it allows |
|---|---|
| `payables.view` | See the schedule and the runs |
| `payables.manage` | Build a run, add and skip lines |
| `payables.approve` | Approve a run |
| `payables.execute` | Release the money |
| `payables.statement-view` | Read a supplier's account |
| `payables.reconcile` | Import statements and match lines |

By default a manager builds and an owner approves and executes. The accountant
reconciles.

## Related

- [Closing the books](/guides/closing-the-books) — cash forecasting, which this
  reads from.
- [Asking before buying](/guides/requisitions-and-quotes) — the step before a
  bill exists at all.
