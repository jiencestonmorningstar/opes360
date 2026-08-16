# Deadlines and risks

Two registers that answer different halves of the same worry: what must the
business do by law and by when, and what could go wrong that nobody has written
down.

## Compliance obligations

A recurring statutory duty — a tax return, a CNPS declaration, a licence
renewal, an insurance policy. Each has a cadence, a next-due date, an owner, and
the evidence it was met.

The point of writing them down is that none of them live in one person's head
any more. The **compliance calendar** answers what is due and what is overdue,
and that single view is most of the value.

### Filing one

Filing an obligation records that it was done, when, and by whom, with the
evidence attached. The evidence is an ordinary
[document](/guides/documents-workspace) — retention, legal hold and versioning
already apply to it, because it is the same kind of thing they always applied to.

A filing can be sent through the ordinary [approval
workflow](/guides/approvals) where somebody needs to sign it off before it
counts.

### The next one — and the distinction that matters

When a filing completes, the next occurrence is raised. **How the next date is
counted depends on the obligation**, and this is a setting, not a rule:

- **From the due date.** A tax return filed three weeks late does *not* move
  the next quarter. The DGI sets the quarters, not you. This is the default.
- **From completion.** A licence renewed in March runs a year from March,
  whatever month it was supposed to be renewed in.

One rule cannot serve both. Getting it wrong is invisible for one cycle and a
year adrift by the fourth, which is exactly the kind of error nobody catches
because nothing ever looks wrong on the day.

(Equipment servicing makes the opposite default choice — see [where your
equipment is](/guides/asset-movements) — because an engine does not care what
the plan said.)

## The risk register

An identified risk: what could go wrong, how likely it is, and how bad it would
be. Likelihood times impact gives the **inherent score** — the risk with nothing
done about it.

Against each risk you record **controls**: what is actually in place to stop it
or to limit the damage.

The **residual score** — the risk as it now stands — is entered by a person, and
is **never** calculated from the controls attached to it. This is deliberate and
worth understanding.

A control written on a form lowers nothing. A fire extinguisher bought and never
inspected does not reduce the chance of a fire spreading. If the system quietly
dropped the residual score every time somebody typed in a control, it would
manufacture a reassuring number that nobody had actually chosen to stand behind
— and reassuring numbers nobody chose are precisely what a risk register exists
to prevent.

Scores are **computed, not stored**, so changing a likelihood changes the score
everywhere at once rather than leaving stale figures in old reports.

### Reviewing

Every risk has a review date. Marking a risk down is a separate permission —
`risks.review` — held by owners and administrators rather than by the person who
owns the risk.

Letting somebody quietly reassess their own risk downwards turns the register
into a list of things that used to worry people.

## Who can do what

| Ability | What it allows |
|---|---|
| `compliance.view` | See the calendar |
| `compliance.manage` | Keep the list of obligations |
| `compliance.file` | Record that a return went in |
| `risks.view` | See the register |
| `risks.manage` | Raise risks, record controls |
| `risks.review` | Reassess a risk's residual score |

`file` is separate from `manage` because keeping the calendar and swearing that
a return actually went in are different acts, and the second is the one somebody
may later have to stand behind.

There is no `compliance.approve`. Being asked is the permission.

## Related

- [Contracts](/guides/contracts) — the commercial version of the same deadline
  problem.
- [Closing the books](/guides/closing-the-books) — the figures most of these
  returns are built from.
- [Approvals and workflows](/guides/approvals)
