# Projects

A project is a piece of work with a budget, a team, and — if it is for a
client — a price. Track its tasks, log time against it, and see what it has
cost so far.

## Setting one up

**Projects → New project.**

- **Name** — required.
- **Code** — optional, a short reference for reports.
- **Budget** — optional. Leave it blank for work you are not tracking against
  a ceiling.
- **Billable to a client** — ticked by default. Untick it for internal work —
  moving office, a certification, anything that costs money and earns none.

A project can be linked to an existing customer. It does not get a customer
list of its own — the one in **Customers** is the only one, and a project
simply points at an entry in it.

## The life of a project

A project moves through five states: **planning → active → on hold →
completed**, with **cancelled** available from any working state.

- A new project starts in **planning** — the budget, team and task list are
  taking shape, and nothing says work has begun.
- **Active** is work in progress. Put it **on hold** when it stalls — a client
  gone quiet, a payment awaited — and back to active when it resumes.
- **Completed** is a claim the task list must agree with: completing is
  refused while any task is still open. Finish the tasks or cancel the
  project — otherwise the status filter becomes a place to hide unfinished
  work. A short job can go from planning straight to completed; forcing a
  two-day job through "active" first would be ceremony.
- **Cancelled** does not check for open tasks — abandoning a project abandons
  its tasks with it, and that is the point of cancelling.

Completed and cancelled are the end of the road: neither can be reopened,
because reviving finished work would silently reopen a budget somebody has
already reported on. Either way the project keeps its **close date** — when
work actually ended, however it ended.

## Tracking cost

Every project shows what it has cost so far: hours logged, at the rate they
were logged at, plus any expenses recorded against it.

**Rates are locked in when the time is logged, not read from the project every
time a report runs.** If a project's rate changes in March, work logged in
January still costs what it cost in January. This is the same principle a
payslip already follows — it reads the salary that was in force on its own
date, not today's.

## Billable vs internal

A project marked **not billable** is left out of billing figures entirely.
Treating an internal project as a loss would make cost reports say things like
"lost 2,000,000 on the office move" — technically true, and useless, because
nobody was ever going to invoice it.

## Things that surprise people

**Cancelling a project does not delete it.** Its tasks, time entries and cost
history all stay exactly as they were — cancelling only stops new work being
logged against it. There is no button that deletes a project outright.

**A task survives its milestone being deleted.** A milestone is a grouping, not
a container: removing "Design phase" does not remove the tasks that were filed
under it, it only ungroups them.

**Once time is locked, it cannot be edited or deleted.** Time gets locked when
it has been billed or a period has closed, on the same principle an issued
invoice cannot be quietly rewritten — a bill already sent must not disagree
with the timesheet underneath it.

## Who can do what

**Managing a project** — creating one, setting its budget, choosing its client
— is separate from **logging time** on it. A Manager can do both. A Sales
Officer can log their own time on a project without being able to touch its
budget. An Accountant can see every project, for costing, without being able to
change any of them.
