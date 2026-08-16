# Approvals and workflows

An approval is how something gets signed off: a purchase, an expense, a
contract. A **workflow** is the rule that says who is asked, in what order, and
what happens when they answer.

There is one approval system for the whole product. Whether you are approving a
document, a purchase order or an expense, it behaves the same way and appears in
the same place — [My actions](/guides/my-actions).

## The shape of a workflow

A workflow is a list of **steps**, run in order. Each step says three things:

1. **Who is asked** — a role, a department's manager, a named person, the
   business owner, or whoever raised the record.
2. **How many must agree** — the first to answer, everybody, or a set number.
3. **When it applies** — optionally, a condition such as "only if the amount is
   over 10,000,000".

When a record is submitted, the first step that applies is assigned to whoever
it names. When that step is satisfied, the next one is assigned. When there are
no steps left, the record is approved.

## Answering

Whoever is asked sees the item in **My actions**, and has four choices:

| Answer | What happens |
|---|---|
| **Approve** | Counts towards the step. When enough people have approved, it moves on. |
| **Ask for changes** | Goes back to whoever submitted it. They fix it and send it round again. |
| **Reject** | Stops it, permanently. |
| **Hand over** | Passes your decision to a colleague. |

**"Reject" and "ask for changes" are different answers, and the difference
matters.** Rejecting is final — the request is dead and a new one must be
raised. Asking for changes is an invitation: attach the missing receipt, correct
the figure, resubmit. If it can be fixed, ask for changes.

## Handing a decision over

Going on leave? **Hand over** passes an outstanding decision to a colleague.
They can then approve it as if they had been asked in the first place.

The record keeps both halves — it shows that you were asked, that you handed it
to them, and that they answered. Nobody can quietly pass a decision to a friend
and have it look like their own.

Once you have handed something over, you can no longer act on it yourself.

## Conditions

A step can be set to apply only in certain cases. The commonest is by amount:

> Under 10,000,000, the manager signs.
> At 10,000,000 and over, it goes to the manager *and then* the director.

Steps whose condition does not match are skipped entirely — they do not wait,
and they do not appear in anyone's list.

**If every step is skipped, the thing is approved.** That is the point: it is
how "small purchases go straight through" is expressed. The submission is still
on the record and still shows who raised it; nobody was simply asked to
rubber-stamp it.

## What your business starts with

A new business is given five approval paths, so that submitting something
actually works on the first day rather than refusing with "no approval path is
defined". They cover staff expense claims, purchase requisitions, contracts,
statutory filings and service visits.

Each one asks **the owner**. Not the person who owns it today by name — the
role, resolved at the moment the step is reached — so the path survives the
business changing hands.

Purchase requisitions are the only one with a threshold on them, because a
requisition for a box of pens should not need the owner. The seeded figure is
**500,000 XAF**, or the equivalent in whatever currency your business trades in.

**That figure is a guess, and it is meant to be changed.** It is roughly a
month of a modest wage bill — high enough that ordinary purchasing is not
interrupted, low enough that nothing significant slips past. Your number is
almost certainly different. Change it on the workflow screen in the first week.

Everything seeded is an ordinary workflow: edit it, add steps to it, turn it
off, or replace it entirely. Nothing will reinstate it underneath you.

## Things that surprise people

**If nobody can fill a step, the approval stops and says so.** If a step is set
to "the Finance manager" and that person has left, the approval goes to
**Stalled** rather than quietly passing. This is on purpose: an approval that
approves itself because its approver left is far worse than one that visibly
gets stuck, because the stuck one gets noticed and fixed. Correct the workflow
or the department manager, then resubmit.

**You cannot approve the same thing twice.** If a step needs two approvals, it
needs two *people*.

**Being asked is your permission to answer.** There is no separate setting to
grant somebody the ability to approve. If the workflow names you, you can act;
if it does not, you cannot — even if you are an administrator.

**Editing a workflow does not rewrite the past.** Rename a step and every
approval already recorded under the old name keeps showing the old name. What
happened, happened.

## Editing the rules

**Settings → Approval rules** is where workflows are written: the steps, who
each one asks (by role, department or relationship — naming a person is the
deliberate exception, because a workflow naming a person is wrong the day
they leave), the quorum, the conditions, the deadlines.

Two behaviours worth knowing:

- **Editing never disturbs approvals already running.** Change a workflow
  while something is mid-approval and the old definition is kept for it —
  it finishes under exactly the rules it started under, and only the next
  submission gets the new ones. You are never told "wait until it
  finishes", because the moment you discover a workflow is broken is
  precisely when something is stuck in it.
- **A step naming a role nobody holds is warned about, not blocked.** You
  may be about to hire — but an approval reaching that step will stall
  visibly rather than pass silently, so the warning is worth heeding.

A new workflow starts switched off and cannot be made default until it has
at least one step. A workflow with no steps would approve everything
instantly, and that is the one way this screen could turn the engine into a
rubber stamp.

## Who can create a workflow

Only the **Owner** and an **Administrator**.

This is deliberately narrow. Whoever can edit a workflow can write themselves a
path with no approver in it — which amounts to being able to authorise their own
spending. Managers and accountants can see what the rules are, so they know what
to expect, but cannot change them.
