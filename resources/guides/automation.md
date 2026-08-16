# Automation rules

A rule says: **when this happens, and these things are true, do that.** Nobody
has to remember.

> When an expense over 10,000,000 is recorded → start the director's approval.

> When a contract is about to expire → tell the person who owns it.

## The three parts

**1. The trigger.** Something that happened in the business — an expense
recorded, a document published, an approval rejected. You choose from a list;
you cannot invent one, so a rule cannot be written against an event that will
never fire.

**2. The conditions** (optional). Narrow it down: only over a certain amount,
only in a certain state. Leave them empty and the rule fires every time.

Conditions are the same ones approval steps use — a field, a comparison, and a
value — so if you have written a workflow condition, you already know how these
work.

**3. The action.** What to do:

| Action | What it does |
|---|---|
| **Start an approval** | Sends the record round a workflow |
| **Notify a person** | Emails and notifies one named colleague |
| **Notify everyone with a role** | Emails and notifies, say, every Manager |
| **Send a webhook** | Posts to a system you have connected |
| **Set a field** | Changes one specific field on the record |

## Things that surprise people

**A rule that fails never breaks the thing that triggered it.** If a rule is
misconfigured, the expense is still recorded and the document is still
published — the rule simply does not run, and the failure is logged. This is
deliberate: an automation that stops you invoicing a customer is far worse than
an automation that quietly does not run.

**One broken rule does not stop the others.** Each is tried on its own.

**"Set a field" cannot touch just any field.** Each kind of record offers a
short list of fields automation is allowed to change — notes, categories,
filing labels. It can never set an expense to paid, change a total, or alter an
invoice's status. Those are decisions with money attached, and they are not
something a settings screen should be able to do.

**Rules only see your own business.** A rule belonging to one business never
fires on another's records, even on identical data.

**Turning a rule off is instant and reversible.** Untick **Active** rather than
deleting, if you only want to pause it.

## Who can create a rule

The **Owner** and an **Administrator**. A rule can start approvals and send
messages on the business's behalf, which makes writing one closer to changing a
policy than to doing a day's work.
