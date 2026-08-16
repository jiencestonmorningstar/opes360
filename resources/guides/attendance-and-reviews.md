# Positions, attendance and reviews

Three things that sit around the staff file: the post somebody holds, the hours
they worked, and what was said at their review.

## Positions

A **position** is the post, not the person in it. "Delivery Driver" outlives
whoever is currently driving, and a business that has three of them wants to say
so once.

Positions work exactly like [departments](/guides/departments). The free-text
**job title** on each employee is still there, and was used to create your
positions the first time this ran. It has not been removed, because payroll
copies it onto payslips and the API still exposes it — a June payslip must go on
saying what it said in June, whatever the org chart does afterwards.

Positions stay available even if you switch the HR module off, for the same
reason departments do: a job title outlives a business turning a screen off.

## Attendance

One record per person per day. That is enforced by the database, not by the
screen — a duplicate day doubles every total built on it, with no way for anyone
reading it afterwards to tell which row was real.

A record can carry clock-in and clock-out times, or a straight number of minutes
worked. If you give the times and not the minutes, the minutes are worked out
for you. If you give the minutes — which is what an attendance device exports —
they are left exactly as they are rather than being recalculated from times that
may not exist.

### Attendance is not wired to payroll

This is deliberate, and it is worth understanding before somebody proposes
"improving" it.

Payroll reads the employment contract in force on the payslip's own date, so a
June payslip reproduces June forever. Attendance, by contrast, gets corrected
weeks later — a missed clock-out, a shift entered against the wrong person.

If a payroll calculation read the attendance table, correcting last month's
timesheet would silently rewrite a payslip that has already been paid and
declared to the CNPS. Nobody would be told.

So any absence deduction is entered onto the payroll run **by hand**, as a
salary component, where a person can see it and sign it off. Attendance is the
evidence for that decision, not the decision.

## Performance reviews

A review carries a period, a cycle, a reviewer, a rating, a summary, strengths,
areas to improve, and goals.

When the employee **acknowledges** it, the verdict freezes: rating, summary,
strengths, improvements, goals, period, cycle, reviewer and position all become
read-only. Their own comment stays editable.

An acknowledgement of a document that can still be edited afterwards is worth
nothing in the dispute it is being kept for.

There is no `acknowledge` permission. An employee acknowledges their own review
by being its subject — exactly as an approver approves by being asked. An
ability would let an administrator grant somebody the right to sign off a review
that is not about them.

## Who can do what

| Ability | What it allows |
|---|---|
| `positions.view` / `positions.manage` | Read and keep the list of posts |
| `attendance.view` | See who turned up |
| `attendance.record` | Write what hours somebody worked |
| `reviews.view` / `reviews.manage` | Read and write reviews |

`record` is not implied by `view`. Seeing that a team turned up is a
supervisor's business; writing the hours that become a wage is not the same
trust.

## Not here yet

**Recruitment** — candidates, applications, interview stages, scorecards, offer
letters and a public application form — is a separate module and is not built.
Positions are the right thing for a future job requisition to point at.

## Related

- [Departments](/guides/departments) — the other half of the org chart.
- [Approvals and workflows](/guides/approvals) — how leave and other requests
  get decided.
