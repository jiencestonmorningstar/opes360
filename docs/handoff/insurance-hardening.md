# Insurance vertical — hardening handoff

The vertical shipped working (see `docs/handoff/insurance.md`). This pass
closed the gap between "works" and "what an insurance broker actually runs
on". Gap list first, then what was built for each, then the two items argued
out of scope and the audit wiring that remains the orchestrator's.

## The gaps found

1. **No renewal act.** The watch raised the lapse alarm and nothing on the
   product could answer it — a broker had to cancel and re-place, losing the
   history that renewal exists to keep.
2. **No mid-term endorsements.** Cover changed mid-term (vehicle swapped, sum
   insured raised) had nowhere to be recorded, and the premium adjustment had
   no path into the books.
3. **Cancellation kept the whole premium.** Cancelling mid-term recorded the
   date and reason but never computed the unexpired pro-rata or returned it.
4. **No instalment billing.** One premium, one invoice — but premiums are
   routinely paid in parts, and the collector needs the whole schedule.
5. **Claim evidence invisible.** `InsuranceClaim::papers()` existed but no
   screen rendered it; evidence could only be found from the Documents side.
6. **Not in global search.** Neither policies nor claims were
   `GlobalSearch` sources.
7. **Events unselectable.** None of the `insurance.*` events were in
   `DomainEvents::CATALOGUE`, so automation and notification rules could not
   listen for them.

## What was built

### Renewal as an act (`Policies::renew`)

The `ContractLifecycle::renew` pattern: the new term and the history row are
written in one transaction or not at all. New cover starts the day after the
old cover ends (no gap, no overlap); the new end date defaults from
`renewal_term_months` with an explicit date winning; `notice_by` is recomputed
by the model's saving hook (one writer for the derived value). History lives
in **`insurance_policy_renewals`** (`InsurancePolicyRenewal`), with
previous/new dates and premiums and a `method` (`auto`/`negotiated`). Emits
`insurance.policy.renewed`. Screen: **Renew** button on the policy page,
prefilled from the agreed term; history panel below the cover.

### Mid-term endorsements (`Policies::endorse`)

**`insurance_endorsements`** (`InsuranceEndorsement`) records what changed,
when it took effect, previous/new premium and the signed `premium_delta`. A
positive delta drafts an ordinary **debit note**, a negative one a **credit
note** — existing `DocumentType` cases, drafted (never issued) because the
broker checks the adjustment against the insurer's endorsement schedule
before it goes out; issuing stays in Sales. Zero delta is paperwork only.
The note is linked through `insurance_endorsements.document_id` *and* an
`insurance_policy_invoices` row, so the policy page's money panel lists it.
Emits `insurance.policy.endorsed`.

### Cancellation with return premium (`Policies::cancel`, `Policies::returnPremium`)

`returnPremium()` is the single definition of the unexpired pro-rata:
straight-line over the term in days, clamped to the term. `cancel()` now runs
in a transaction; when the return is above zero **and an issued premium
invoice exists**, it drafts a credit note for the return (capped at the
invoice total) with `parent_document_id` pointing at that invoice — the same
parentage `DocumentConverter` uses, so crediting rules can see it. A premium
never billed returns nothing: money that never moved has nothing to come
back. The cancel form shows the computed return before the user confirms.

### Instalment schedules (`Policies::invoicePremiumInstalments`)

N (2–12) ordinary draft invoices generated **up front**, due dates a month
apart, each linked through `insurance_policy_invoices`. The slice is floored
at the currency's own precision (XAF/XOF are zero-decimal, the same fact
`Vat` rounds by) and the rounding remainder lands on the first instalment, so
the schedule sums to the premium exactly.

**Why not `RecurringInvoice`:** that models an open-ended rhythm billing
forward as wall-clock time passes. An instalment plan is a fixed N slices of
a known total tied to one cover period — the client agreed all N due dates
when the policy was placed, and aging/dunning need to see the whole schedule
from day one. A generator that produces invoice 3 only when month 3 arrives
gives the collector nothing to chase ahead of time.

All four premium-side documents (invoice, instalment, debit note, credit
note) now go through one protected drafting path in `Policies`, so the VAT
treatment, the link row and the draft-first rule cannot drift between them.

### Claim evidence on screen

Each claim on the policy page renders `<x-documents.library-panel
:record="$claim" title="Evidence" :limit="4" />` — view, open and add through
the shared library, gated by `papers.view` as everywhere else. The claims
board (`Insurance\Index`) shows the evidence count per card; the card already
links to the policy page where the panel lives.

### Global search (`app/Search/GlobalSearch.php`, additive)

- `InsurancePolicy` → title "holder — product line", subtitle policy number,
  route `insurance.show`, ability `insurance.view`.
- `InsuranceClaim` → title "claim number — holder", body = description,
  routes to **the policy page** (a claim has no page of its own), ability
  `insurance.view`.
- `GROUP_LABELS`: `Policies`, `Claims`. Observers attach automatically —
  `GlobalSearch::observe()` iterates `sources()`.

### Domain events (`app/Support/DomainEvents.php`, additive)

New `insurance` module block: `policy.placed/bound/renewed/endorsed/cancelled`,
`premium.invoiced`, `claim.opened/settled/rejected`. These are the names the
services already emit; the catalogue makes them selectable by rules.
(`premium.invoiced` fires at draft; issuing is Sales' moment and already has
`sales.document.issued`.)

### Demo scene (`DemoModulesSeeder::insurance` only)

Added to the existing scene, inside its existing idempotence guard
(`InsurancePolicy::exists()` — verified seed-twice clean, counts stable):

- a **renewed** property policy: old term in the history row, premium
  600 000 → 660 000, plus an **endorsement** (second oven on the contents
  schedule) whose additional premium is a drafted debit note;
- a health policy billed in **4 instalments, 2 issued and paid** through
  `DocumentIssuer` + `PaymentRecorder`, 2 still drafts with due dates set.

### Guide

`resources/guides/insurance.md` gained sections on renewing, endorsements,
cancellation with return premium, instalments, and the evidence panel.
(Content only — the Guides catalogue is the orchestrator's.)

## Argued out of scope

**PDF.** No print view was added, deliberately. The policy schedule is the
*insurer's* document — it arrives as a managed paper and lives in the
library; this system reprinting its own rendition of somebody else's schedule
would be a forgery invitation, not a feature. The claim settlement letter is
correspondence, which is what `BusinessDocumentTemplate` already does better
(templated, versioned, signable) than a hard-coded `PrintController` view
would. The money documents (premium invoices, debit/credit notes) already
print through the existing document print path because they *are* ordinary
documents. If a printed renewal summary is ever wanted, follow the
`PrintController`/`App\Support\Pdf` conventions — but nothing here needs it
today, and `routes/*` is not this vertical's to touch anyway.

## For the orchestrator (AppServiceProvider)

**AuditObserver registration** — these models carry money-adjacent state and
belong in the audit list:

- `App\Models\InsurancePolicy` (status, dates, premium)
- `App\Models\InsuranceClaim` (status, settled amount)
- `App\Models\PolicyCommission` (amount, invoicing)
- `App\Models\InsurancePolicyRenewal` (immutable history, but creation should
  be attributable)
- `App\Models\InsuranceEndorsement` (premium delta and its document)

`InsurancePolicyInvoice` is a pure link table; auditing the linked Document
(already audited) covers it.

Everything else from the original handoff (routes, permission group, module
entry, listener, nav) is unchanged.

## Verification

- `php artisan test --filter="Insurance"` — 50 passing (28 new).
- `php artisan test --filter="Insurance|Search|Document"` — green.
- Demo seeded twice on a scratch database: 3 policies / 1 renewal /
  1 endorsement / 6 invoice links both times.
- Pint clean on all touched files.
