# Contracts

An agreement with somebody else: a customer, a supplier, a landlord, an
insurer. What was agreed, what each side has to do, when it ends, and — the
part that actually costs businesses money — whether it renews itself if nobody
says anything.

## The notice date is the point

Most contract software is a filing cabinet. The thing that loses real money is
narrower than that: a contract that renewed for another year because the date by
which you had to give notice went past and nobody saw it.

So every contract carries a **notice by** date — the last day you can serve
notice — worked out from its end date and its notice period. It is stored on the
record, not calculated when you look, so it can be searched and sorted and can
never quietly disagree with the end date it came from.

The watch screen keeps three lists, deliberately separate rather than merged
into one you would skim:

1. **Notice deadline coming** — you can still act.
2. **Notice deadline gone** — you cannot, on a contract that simply ends.
3. **Notice deadline gone on a contract that renews itself** — you are committed
   to another term. This is the list that should never have anything in it.

A contract that renews automatically and has an end date but **no notice
period** is refused when you raise it. That combination produces no date anybody
could ever be warned about, and accepting it would mean the system silently
promising a warning it cannot give.

## Raising one

A contract needs the other party (an existing customer or supplier — there is no
separate contract address book), a type, a value, and its dates.

**Submit** sends it through the ordinary [approval workflow](/guides/approvals).
There is no separate approval here, no approve button on the contract itself,
and no permission to approve — as everywhere in this product, being asked is the
permission.

When it is approved it becomes **active**. There is no extra step. A contract
that has been signed off and is still sitting in a queue waiting for somebody to
press "activate" is a contract nobody is watching the dates on.

## The paper

The signed PDF is an ordinary [document](/guides/documents-workspace) attached to
the contract, not a second kind of file living somewhere else. That means
versioning, retention, legal hold, sharing and e-signature all already apply to
it, because it is the same thing they have always applied to.

Contracts have **no number of their own**. The document has one.

## Obligations

What each side has to do, and by when: a deliverable, a report, a payment, an
inspection. Each has an owner and a due date, and is either done or not.

Obligations are where a contract stops being a filing cabinet entry. The
question "are we actually complying with what we signed" has no answer without
them.

## Renewing and terminating

**Renew** extends the contract and records the extension in its history, so the
sequence of terms is visible rather than being a single end date that has been
edited several times.

**Terminate** ends it early. Both are separate permissions from writing one,
because both either commit the business to another term or end something it is
being paid under.

## Who can do what

| Ability | What it allows |
|---|---|
| `contracts.view` | See contracts and the watch screen |
| `contracts.manage` | Raise, edit, submit, add obligations |
| `contracts.renew` | Extend for another term |
| `contracts.terminate` | End one early |

By default a manager writes and renews; ending one early stays with the owner.

## Related

- [Approvals and workflows](/guides/approvals)
- [Deadlines and risks](/guides/compliance-and-risk) — the statutory equivalent
  of the same problem.
