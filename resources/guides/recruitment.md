# Hiring

From advert to employee, with the record kept at every step. Switch it on
under **Settings → Modules → Recruitment** — it ships off, because most
businesses here hire once a year by word of mouth, and an empty pipeline
screen teaches people to ignore screens.

## The shape of it

A **vacancy** points at a [position](/guides/attendance-and-reviews) — the
post, which already exists. It gets a public link and QR: anybody can read
the advert and apply, with their CV, no login. The application lands in your
pipeline; the tenant is resolved from the link's token, the same way public
forms work.

Applications move applied → screening → interview → offer → hired, or are
rejected at any stage with a reason. Every move is kept: who moved them,
when.

**Interviews** are scheduled against an application, with a scorecard per
interviewer. A panel member with only the `interview` ability scores the
candidates in front of them without sight of the rest of the pipeline —
most interviewers should not know what the other applicants asked for.

## Offers

An offer is the money-shaped act: it commits a salary every month from now
on. So:

- The **offer letter** is an ordinary [document](/guides/documents-workspace)
  from a template — confidential, versioned, retained like any other.
- The offer goes through the ordinary [approval
  workflow](/guides/approvals). A new business is seeded with "Owner
  approves", with **no threshold — there is no such thing as a wage too
  small to sign.**
- Accepting an approved offer **hires**: a real employee is created on the
  same path the Team screen uses, linked to the position, and the candidate
  record points at who they became.

An offer cannot be accepted before its approval finishes — and the check
asks the engine, not a status column, so nothing hand-edited can hire
anybody.

## Rejected candidates

A rejected candidate's record can be purged — their file is personal data
held for a purpose that has ended. How long to keep it before purging is a
policy question this module raises but does not decide for you.

## Who can do what

| Ability | What it allows |
|---|---|
| `recruitment.view` | See the pipeline |
| `recruitment.manage` | Vacancies, stage moves, interviews |
| `recruitment.interview` | Score the candidates in front of you, nothing else |
| `recruitment.offer` | Make and send offers |

The manager runs the hiring and sits on panels; `offer` stays with the
owner, for the same reason approving one does.

## Related

- [Positions, attendance and reviews](/guides/attendance-and-reviews)
- [Approvals and workflows](/guides/approvals)
