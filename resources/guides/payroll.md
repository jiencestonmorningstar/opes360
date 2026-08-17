# Paying your staff

Payroll is the one part of this system that is genuinely periodic. There is no
such thing as an ad-hoc payslip: you run a month, check it, approve it, pay it.
Open it from **Payroll**. The screen is a list of months, and starting one is
the only action on it.

Before the payroll can pay anybody, though, the HR side has to know who they
are and what they are on. That lives under **Team**.

## The staff file

Each person's page has four panels: **profile**, **contracts**, **pay** and
**leave** — panels rather than pages, because the questions a manager arrives
with ("what is she on now", "when does his CDD end", "how much leave has she
left") are answered by looking at them together.

The profile holds the identifiers the payslip needs — CNPS number, NIU,
national ID — and how the person is paid: cash, bank or mobile money.

When someone leaves, mark them as having left rather than deleting them. The
record and the contracts stay; only the status changes. A business asked for
last year's payroll cannot have people missing from it because they have since
resigned. A suspension is a pause, not a departure — record and contract stay
exactly as they are.

## Contracts

The salary lives on the **contract**, not the person. A payroll run reads the
contract in force at the end of the month, so somebody with no contract
covering that month is on the books but not on the payroll — a person hired on
the 3rd of next month is not paid for this one.

Recording a new contract closes the old one on the day before the new one
starts. Two active contracts would mean an employee paid twice, and a raise is
exactly this: a new contract from the 1st, at the new figure.

## Allowances and deductions

Under the **pay** panel, add standing components: an allowance (transport,
housing, a prime) or a deduction. Each allowance carries two flags — whether
it is **taxable** and whether it is **CNPS-liable** — and those flags are why
"salary" is never one figure. The payroll computes three bases:

- the **gross** — everything earned;
- the **taxable gross** — minus exempt allowances, the base for IRPP, CFC and
  FNE;
- the **CNPS base** — capped at the ceiling (750 000 F) for pension and family
  allowances, but *not* for occupational risk, which follows the whole salary.

They coincide for someone on a flat salary and diverge the moment anyone gets
a transport allowance or earns above the ceiling. Switch a component off when
it stops applying; it simply leaves the next payslip.

## Leave

Leave is requested against a person, suggested in working days, and approved
by whoever holds the approval ability. Approved leave falling inside a payroll
month is shown on that month's payslip. Each person's balance starts from an
opening balance on their profile, and only leave types configured to deduct
the balance draw it down.

## Running a month

**Start** a month and it is built in one step: a payslip per active employee
with a contract, each line computed — CNPS pension both sides, IRPP
annualised the way the DGI reconciles it, the centimes additionnels, CFC, TDL
and RAV where they apply, plus the employer's own charges (family allowances,
occupational risk, CFC and FNE).

A run moves through four states, in one direction:

- **Draft** — built and rebuilt as many times as it takes. Change a contract
  or an allowance, rebuild, and the previous draft is thrown away rather than
  half-reconciled. Nothing outside the run knows it exists yet.
- **Approved** — frozen and posted to the books, in one entry: the gross and
  employer charges as costs, and what is owed to the staff, to the CNPS and to
  the state as three separate debts, because they are settled on three
  different dates to three different people.
- **Paid** — the net has actually left the bank or the till, and the books say
  so.
- **Void** — reversed.

## Why a paid run voids rather than edits

The moment a run is approved, its figures exist in three places you do not
control: the ledger, the CNPS declaration, and the employees' hands. Editing
would make those three disagree with each other and with what was declared.
So a mistake in an approved run is corrected by **voiding** it — which
reverses the ledger entry rather than erasing it — and running the month
again. A run that has already been paid must have its payment reversed first.

Two more things the run does for the same reason:

- **It records the rates it used.** The legal rates will change; these
  payslips must not. Anything that ever explains a past payslip computes with
  the rates recorded at approval, not today's.
- **Each payslip snapshots the person.** Name, CNPS number, job title,
  department — as they were that month. A corrected spelling three years
  later must not reissue history.

Payslips can be printed, and the run exports a register as CSV for the bank
or the declaration.

## Who can do what

| Ability | What it allows |
|---|---|
| `payroll.view` | See the runs, the payslips, the register |
| `payroll.run` | Start a month and rebuild its draft |
| `payroll.approve` | Freeze a run and post it to the books |
| `payroll.pay` | Record that the net was actually paid out |
| `payroll.void` | Reverse a run that should not stand |
| `employees.view` | Open the staff file |
| `employees.create` / `employees.update` | Keep it: profiles, contracts, allowances |
| `leave.request` | Ask for leave |
| `leave.approve` | Decide it |

Run, approve and pay are three abilities on purpose: an accountant runs the
payroll, but approving a month posts it to the books and paying it releases
the money, and neither should follow automatically from being able to compute
it. Salaries are also the one thing everybody is curious about and almost
nobody should see — which is why the staff file, the payroll and leave are
separate groups rather than one.

## Related

- [Positions, attendance and reviews](/guides/attendance-and-reviews) — the
  posts people hold, the hours they work, and the reviews on their file.
- [The books, in plain words](/guides/accounting) — where the payroll entry
  lands.
- [Spending money](/guides/expenses) — salary is not the only money going out.
