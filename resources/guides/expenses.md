# Spending money

Money leaves a business two ways: it buys something from a supplier, or a
member of staff pays for something out of their own pocket and wants it back.
This system keeps those apart on purpose — **Expenses** for the first,
**Expenses → Claims** for the second — because they are different debts to
different people, and the abilities that govern them are different trusts.

## Supplier bills and direct spending

**Expenses** is one list for supplier bills and day-to-day cash spending
alike. What separates them is the **due date**:

- Leave the due date blank and it is a cash purchase — paid on the spot, by
  cash, bank or mobile money, and done.
- Give it a due date and it is a **bill**: recorded now, owed until settled.

The filter on the list sorts by what is still owed — all, owing, overdue,
paid — because that is the distinction anyone actually cares about at the end
of a month. The totals at the top are computed over the whole period, not the
page on screen.

Every expense takes a **category** (transport, goods, rent, …). The category
decides which expense account the books charge, so a well-categorised month is
a month whose income statement means something.

### Settling a bill

Press **Pay** on anything still owing. The amount defaults to the whole
balance, but part-payment is just a matter of editing the figure; the bill
stays open until the balance reaches zero.

### Voiding

An expense recorded in error is **voided**, not deleted: its ledger entry is
reversed, and the record stays as evidence that it happened and was undone.
The books have to keep saying what they said.

## TVA you can reclaim

If your business is TVA-registered, record the rate on each expense and the
deductible TVA is posted to its own account (`TVA récupérable`) rather than
buried in the cost. That figure is what you set against the TVA you collected
on sales when the declaration is due — record it as part of the price and you
pay the state twice. The rate defaults to 19.25% for a registered business and
to zero otherwise.

The same happens on the lines of an approved expense claim: the TVA on staff
receipts is reclaimable too, and the books treat it identically.

## Staff expense claims

A claim is money an employee paid personally — a taxi, a meal, materials
bought on site — and is owed back. It is deliberately **not** just an
expense, because between being approved and being repaid the employee is a
creditor of the business, and the books have to say so.

Open **Expenses → Claims**. A claim has a title, a date, and one or more
lines, each with its description, category, amount, TVA rate and, if you use
them, a cost centre — so the cost lands where it was incurred, not in a
lump.

A claim moves through:

1. **Draft** — created, still editable in effect: nothing is in the books.
2. **Submitted** — handed to the approval engine. Claims are approved in the
   workflow inbox, not on this screen; there is deliberately no approve
   button here, because a second place to approve something is how a product
   ends up with two answers. There is no `claim-approve` ability either —
   being asked by the workflow *is* the permission.
3. **Approved** — posted: the costs are charged, the reclaimable TVA
   recorded, and what the business now owes the employee sits as a staff
   debt — staff, not supplier, so the supplier ageing report does not start
   chasing the person who bought the taxi.
4. **Reimbursed** — the employee has been paid back.

### Reimbursing

Press **Reimburse** on an approved claim. The amount defaults to the whole
balance; partial reimbursement is allowed, and the claim only closes when the
balance is cleared. Paying more than is owed is refused, and two people
paying the same claim at the same moment cannot both succeed. Each repayment
clears the staff debt and credits the till or the bank it came out of.

## Who can do what

| Ability | What it allows |
|---|---|
| `expenses.view` | Open the expenses list |
| `expenses.create` | Record a bill or a cash purchase |
| `expenses.update` | Correct one |
| `expenses.pay` | Settle what is owed to a supplier |
| `expenses.void` | Reverse one recorded in error |
| `expenses.claim-view` | Open the claims screen |
| `expenses.claim-create` | Raise a claim and submit it for approval |
| `expenses.claim-reimburse` | Pay an approved claim back |

The `claim-*` abilities are separate from the rest on purpose, and the claims
screen is gated on its own ability rather than `expenses.view`: a sales
officer claims back their own taxi fare without any sight of what the
business spends, and a clerk who may enter their own fare has no business
paying the electricity bill.

Note what is *not* here: recording money coming in is the `payments` group.
A cashier who may take a customer's money has no business recording what the
company spends, and the catalogue keeps the two apart.

## Related

- [Deciding which bills to pay](/guides/paying-suppliers) — building a payment
  run against the cash you actually have.
- [Asking before buying](/guides/requisitions-and-quotes) — requisitions and
  quotes, for spending that needs agreement first.
- [Approvals and workflows](/guides/approvals) — how a submitted claim finds
  its approver.
- [The books, in plain words](/guides/accounting) — where all of this lands.
