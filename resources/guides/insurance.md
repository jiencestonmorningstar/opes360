# Broking insurance

A brokerage lives or dies on two dates and one number: the day a client's
cover runs out, the last day anybody can still do something about it, and the
amount a claim is settled at. This module is built around exactly those three
things. Everything else — the premium invoice, the commission, the evidence on
a claim — is the ordinary machinery of the product wearing an insurance label.

## Placing and binding cover

A policy names a **policyholder** (a customer from your contact book) and,
usually, an **insurer** (a supplier contact — they are somebody you receive a
service from and invoice commission to). Record the product line, the premium,
the agreed commission percentage, and the cover dates.

One combination is refused at the door: a policy that **renews automatically**,
has an end date, and carries **no notice period**. That policy would roll the
client's premium over in silence forever, because there is no date anybody
could be warned by. If it renews itself, say when the last day to decline it
is.

**Bind** the policy when the insurer goes on risk. From that moment it is on
the watch.

## The watch: cover lapsing unagreed

The landing page is not the register — it is the watch, three lists kept
deliberately apart:

1. **Cover ending** — the notice window is open; you can still rebroke,
   renew, or let it lapse on purpose.
2. **Cover lapsed** — the client is bare. Ring them.
3. **Lapsed on auto-renew** — the red list. The policy renewed itself past its
   date, nobody agreed the new term, and nothing has been billed for it. This
   list should never have anything in it, and that is why it is at the top.

The alarm is cover that lapses **before renewal is agreed** — a client driving
uninsured, or paying for a term nobody negotiated, is the thing this screen
exists to prevent.

## Renewing: the answer button to the alarm

The watch tells you cover is about to lapse; **Renew** is what you do about
it. Renewing writes a new term — starting the day after the current cover
ends, so there is no gap the client is bare through and no overlap they are
billed twice for — takes the new premium if it moved, and keeps the old term
in the **renewal history** on the policy. The history is the record that
answers a client asking why the premium has risen three years running, and
your defence when somebody claims a term was never agreed.

The dates prefill from the agreed renewal term, so the ordinary case is one
click and an unusual one is an edit, not a calculation. Once renewed, invoice
the new term's premium as usual.

## Endorsements: cover changed mid-term

A vehicle swapped, the sum insured raised, a driver added — record it as an
**endorsement**: what changed, when it took effect, and the premium after the
change. If the premium moved, the difference is drafted automatically in
Sales as an ordinary **debit note** (the client owes more) or **credit note**
(premium comes back), reviewed and issued there like everything else. An
endorsement that moves no money — a corrected registration plate — is
paperwork only, and still on the record.

## Cancelling with the premium returned

Cancelling mid-term computes the **unexpired pro-rata** of the premium —
straight-line over the term in days — and shows it before you confirm. If the
premium was invoiced and issued, a credit note for the unexpired portion is
drafted against that invoice; if nothing was ever billed, nothing is
credited, because money that never moved has nothing to come back.

## The premium and the commission

**Invoice the premium** and a draft invoice to the policyholder appears in
Sales — an ordinary invoice, reviewed and issued there, chased by the same
dunning as everything else. The policy keeps a link to it, never a copy.

**Record the commission** the insurer owes you for the placing — defaulting to
the agreed percentage of the premium, with an explicit amount winning when
agency terms say otherwise. Then **invoice the commission** to the insurer:
again an ordinary invoice, so the receivable ages on the insurer's statement
like any other money you are owed.

**Instalments.** Where the client pays the premium in parts, **bill in
instalments**: all N draft invoices are generated up front, due dates a month
apart, each linked to the policy. The whole schedule exists from day one, so
aging and dunning can see every due date — a schedule the collector cannot
see in full is a schedule nobody chases.

## Claims: assessed, then settled through approval

A claim is opened against the policy with its incident date — checked against
the **cover dates, not the policy's status**, because a claim for an incident
during cover is valid even after the policy expires, and late-reported claims
are the common case.

The life of a claim: **notified → assessed → settled** (or rejected, with the
reason kept). Each claim carries an **Evidence** panel on the policy page —
photographs, the assessor's report, the repudiation letter — managed
documents through the shared library, with versioning and retention rather
than a poorer copy of each.

Settling is the one act with money in it, and it is built the way every
money-committing act here is built:

- The settlement amount is written on the claim **before** it goes for
  approval, so the approvers approve a number, not a blank — and the claim
  settles at **the amount the approvers saw**, never one typed in afterwards.
- There is **no approve button on the claim**. Approval happens in the shared
  workflow, in the approver's own inbox; when the answer is yes, the claim
  settles itself. One person assessing while another approves is what makes
  this a control rather than a speed bump.
- A settlement still sitting with an approver cannot be settled behind their
  back, and where a settlement workflow is defined, only its yes opens the
  door.

## Who can do what

| Ability | What it allows |
|---|---|
| `insurance.view` | See the watch, the register and the claims board |
| `insurance.manage` | Place and bind cover, invoice premiums, record commission, work claims |
| `insurance.settle` | Settle a claim — the act that commits money to a client |

`settle` is split out and held high for the same reason payment execution is:
it is the number the client is paid. There is no `approve` permission —
being asked by the workflow is the permission, as everywhere else.

## Related

- [Contracts](/guides/contracts) — the notice-date reasoning the policy watch
  borrows, applied to every other agreement your business holds.
- [Approvals and workflows](/guides/approvals) — how a settlement reaches the
  right person, and what happens when they answer.
- [Finding a document](/guides/documents-workspace) — where claim evidence
  lives and how it is attached.
