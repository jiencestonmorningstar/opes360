# The audit trail

Who changed what, when, and from what to what. Most of the time nobody looks. It
exists for the day somebody has to.

## The screen

**Audit** lists every recorded change, filterable by who did it, which record it
was, what kind of action, and when.

Until recently this data existed and there was no way to look at it, which made
it worth almost nothing — a log nobody can read is not a control, it is storage.

Each entry keeps the record's **name at the time**, not just its identifier. A
deleted record still reads as "Invoice INV-0412 for Ets Kamdem" rather than a
row of characters, and a deleted record is precisely the case the log is kept
for.

## Per-record history

Any record's own page can show its history: every change to that one thing, in
order. This is the version most people actually use — not "what happened in the
business" but "what happened to *this*".

## What is recorded

The rule is **money, permissions, or a person's record** — and only where the
record can be changed after the fact.

Deliberately excluded: journal lines, document lines, stock movements. Those are
immutable or append-only, so they are already their own record, and logging them
would bury the signal in churn. A posted journal entry is watched at the header,
where the story is.

Permission grants and revocations are recorded. That was the largest blind spot
until recently: somebody could be given the ability to pay suppliers and nothing
anywhere said so.

## Reading is recorded too — but only sometimes

Some reads are worth logging. Most are not, and logging every read of every row
would swamp the table until nothing could be found in it.

The line drawn is narrow: a read is recorded when it is

- **one named person's confidential record** — a payslip, a performance review,
- **an explicitly restricted document**, or
- **an export**.

Ordinary reads are limited to one entry per person per record per fifteen
minutes, so scrolling a screen does not produce forty rows. **Exports are never
grouped** — two exports are two copies of the data now loose in the world, and
each one matters separately.

The log never copies the contents. It records that something was read, not what
it said.

## Governance: who can do what

The **Governance** screen shows the permission matrix — every person and what
they can do — and, more usefully, a report of **combinations that should not sit
together**.

The classic example is one person who can both enter a supplier bill and pay it.
Each ability is reasonable; together they mean one person can invent a supplier
and pay them. The report finds these by looking at what people actually hold,
including hand-made grants and revocations, not just at the role names.

**One finding worth a decision from you:** the seeded **Manager** role currently
holds both `expenses.create` and `expenses.pay`. That may well be right for a
business of five people, where insisting on two pairs of hands means nothing
gets paid. But it should be a choice somebody made, not an accident, so it is
pinned by a test that will fail if it changes silently.

The payables split — building a payment run and approving it are different
permissions — is correctly separated, and is also locked by a test.

## Who can read it

| Ability | What it allows |
|---|---|
| `audit.view` | The trail |
| `audit.govern` | The permission matrix and the conflict report |

Both are Owner and Administrator only. The trail holds every change anyone has
made, and the governance report names the people whose permissions conflict —
handing either to a wider audience turns a control into a surveillance tool, and
the second one tells whoever reads it exactly which combination of abilities
goes unwatched.

## Audit is not a switchable module

Every other feature here can be turned off under Settings. This one cannot, on
purpose. Switching a module off denies its abilities, so a switchable audit
would let a business turn off its own trail — and the person with both the
motive and the ability to do it is the same person.

## Known limitation

There is no retention policy on the log yet, and it now grows considerably
faster than before. On a busy business this will need pruning, and pruning an
audit log is itself a decision that needs a rule — how long, and who may run it.
It is not solved.

## Related

- [Who gets told what](/guides/notification-rules)
- [Approvals and workflows](/guides/approvals)
