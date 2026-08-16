# Asking before buying

A purchase order is a commitment. This is everything that happens before one:
somebody asks for something, the business decides whether it is worth buying,
and suppliers are asked what they would charge.

Turn it on under **Settings → Modules → Requisitions & sourcing**. It ships off,
because asking a shopkeeper to raise a requisition to buy a broom is the
caricature of an ERP this product exists not to be. Switch it on the day the
business grows into it.

## The shape of it

```
Requisition  →  approval  →  RFQ  →  quotations  →  compare  →  award  →  draft order
```

Purchase orders, goods receipts and three-way matching already existed and are
untouched. This feeds into them.

## Requisitions

A requisition is a request: *we need these things.* Anybody doing the job can
raise one — asking for something is not the same as spending money, and the
whole point is that the asking is visible.

Submitting it sends it through the ordinary [approval
workflow](/guides/approvals). There is no separate approval mechanism here and
no `approve` permission: **being asked is the permission**. Who is asked, and in
what order, is set up once in your workflow rules, including thresholds — "over
10,000,000 needs the director" is a condition on a step, not something this
module knows about.

If an approver asks for changes, the requisition comes back as **returned**,
which is a different thing from **rejected**. Rejected is no. Returned is not
like that.

## Requests for quotation

Once approved, open an **RFQ** and invite suppliers. Each records what they came
back with: prices, lead time, terms.

**A supplier who was not invited cannot quote.** The trail has to show that every
price you considered was one you asked for.

A supplier's quotation is *their* paper. It keeps their reference and stays
outside your document numbering — it is not one of your documents and pretending
otherwise would put your company's number on somebody else's offer.

## Comparing and awarding

The comparison ranks on **price only**. Lead time and terms are shown beside it
for you to weigh yourself, deliberately not folded into a single hidden score. A
score that quietly decides how much a week of delay is worth is a score nobody
can argue with, and the argument is the point.

Awarding raises a **draft, unnumbered purchase order**. Awarding is a sourcing
decision; issuing the order is the commitment. Leaving the numbering to the
existing issuer means a change of mind does not burn a PO number.

### Skipping the RFQ

**Order directly** exists for the single known supplier and the genuine
emergency. It skips the quotation stage — it does **not** skip the approval.

## Who can do what

| Ability | What it allows |
|---|---|
| `procurement.requisition-view` | See requisitions |
| `procurement.requisition-manage` | Raise and submit one |
| `procurement.rfq-view` | See RFQs and quotations |
| `procurement.rfq-manage` | Open an RFQ, invite suppliers, record quotations |
| `procurement.rfq-award` | Choose a quotation and raise the order |

By default staff can raise requisitions, managers run the RFQs, and owners and
administrators award. Approving needs no ability at all — see above.

## Related

- [Approvals and workflows](/guides/approvals) — where the decision actually
  gets made.
- [Deciding which bills to pay](/guides/paying-suppliers) — the far end of the
  same process.
