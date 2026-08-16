# The service desk

A customer has a problem. Somebody has to go and fix it, within the time you
promised. That is the whole feature.

Turn it on under **Settings → Modules → Service desk**. It ships off — most
businesses on this product do not run a desk with technicians and response-time
promises.

## Tickets and jobs

A **ticket** is the problem: which customer, what is wrong, how urgent, how it
reached you. A **job** is a visit that works on it — a technician, a date,
what was done, what parts were used.

One ticket can take several visits. Keeping them apart is what lets you answer
"how many times did we go back" — which is usually the more interesting question.

Customers are ordinary [contacts](/guides/departments). Equipment is an ordinary
[fixed asset](/guides/asset-movements): when a job is maintenance on a machine
you own, completing the job **completes that asset's maintenance record** and
raises the next one. It does not write a second, parallel service history.

## Time on a job

Hours worked on a service job go into the **same timesheet** as project time.
There is one place a person's hours live, not two.

A job can belong to a project as well, and then those hours show up in the
project's cost. A walk-in visit belongs to no project, which is why the link is
optional.

Once time has been billed it locks, exactly as project time does.

## Billing

Completing a job can draft an ordinary **invoice**. Drafting means drafting —
somebody still has to look at it and issue it.

The job holds a link to that invoice. It never holds a copy of the amount. There
is one invoice generator in this product, and this is not a second one.

Finishing the work and charging for it are separate permissions, because they
are decided by different people. A technician can say the machine runs again;
whether that visit is chargeable under the customer's contract is not their
call.

## The SLA clock

This is the part worth understanding, because it is where most service software
quietly lies to you.

A policy sets, per priority, how long you have to **respond** and how long to
**resolve**. Deadlines are counted in **working hours** against a calendar with
your opening times, lunch breaks and public holidays — not in wall-clock hours.
Counting wall-clock means every ticket raised at 16:00 on a Friday has breached
by Monday morning through nobody's fault, and a board full of false breaches
gets ignored within a week.

### When the clock stops

- **Waiting on the customer.** You asked a question and nobody has answered. A
  weekend of customer silence gives back nothing extra, because the credit is
  measured in working minutes too.
- **While resolved.** So a ticket reopened three weeks later does not arrive
  already breached.

Deadlines are always **recalculated** from when the ticket opened plus the
target plus however long it was paused — never nudged forward a bit at a time.
That means pausing, escalating and reopening can happen in any order, any number
of times, and produce the same answer. A clock that is adjusted step by step
drifts, and a drifting SLA is discovered at the worst moment.

### The board

**What has breached** and **what is about to**. The at-risk horizon walks the
same working calendar, so "at risk within four hours" means four *working*
hours.

A sweep runs every fifteen minutes to move the clocks. Hourly would mean a
quarter of the warning window on a four-hour target could pass before anybody
was told, which turns an early warning into an announcement that it is already
too late.

## Who can do what

| Ability | What it allows |
|---|---|
| `service.view` | See tickets and the board |
| `service.create` / `.update` | Raise and edit tickets |
| `service.assign` | Give a ticket to somebody |
| `service.schedule` | Book a visit |
| `service.complete` | Mark work done |
| `service.bill` | Draft the invoice for it |
| `service.manage-sla` | Change what you have promised |

`manage-sla` stays with the owner. What the business has promised its customers
is a commercial commitment, and a manager under pressure must not be able to
relax the target they are being measured against.

## Known limitation

Editing a policy's calendar does **not** recompute deadlines on tickets already
open. Those keep the promise that was in force when they were raised — which is
arguably correct, but it is not a decision anybody made deliberately, and a
business that changes its opening hours should know it.

## Related

- [Projects](/guides/projects) — the timesheet these hours share.
- [Where your equipment is](/guides/asset-movements) — maintenance visits.
- [Contracts](/guides/contracts) — what you agreed to provide.
