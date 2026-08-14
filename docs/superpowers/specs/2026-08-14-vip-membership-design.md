# VIP Membership — design

**Date:** 14 August 2026
**Status:** approved, ready for an implementation plan
**Module key:** `vip`

---

## 1. What this is

A paid, tiered membership programme for restaurants and hotels. A customer buys
a tier — Gold, say, at 50,000 FCFA a year — and for as long as it runs, every
invoice raised for them carries an automatic discount.

It is a separate module from Loyalty, and that separation is deliberate. Loyalty
is a *free points balance* the customer earns by spending. VIP is a *paid term*
the customer buys. The two can run side by side on the same contact — a VIP
member still earns points — but folding them together would put two unrelated
economic models behind one switch and one settings screen.

### Decisions taken

| Question | Answer |
|---|---|
| How does someone become VIP? | They pay for it, for a fixed term |
| What does a tier do? | Applies an automatic discount to invoices |
| How does the discount appear? | As its own figure on the bill, not hidden in unit prices |
| How is the membership sale recorded? | As a normal invoice, through the existing services |
| What happens at expiry? | Benefits stop, the record is kept |

### Out of scope for v1

Auto-renewal billing, prepaid wallets, tier promotion from spend, per-item
discount exclusions, and any interaction with Loyalty points. Each is reachable
later without rework; none is needed to make the module useful.

---

## 2. The foundation: discounts do not currently work

This is the part to understand before anything else, because the module cannot
be built on top of what is there today.

`document_lines` carries `discount_type` and `discount_value`, and
`DocumentConverter` faithfully copies both when a quotation becomes an invoice.
But `Vat::compute` — the function every invoice total in the books passes
through — computes `quantity × unit_price` and never reads them. The columns are
stored, copied forward, and change no total. **There is no working discount
anywhere in the system.**

So VIP's discount is not a feature bolted onto existing discounting. It means
making discounting work at all, inside the code the books depend on.

### The rule that matters

**A discount reduces the taxable base.** TVA is owed on what was actually
charged. On a 100,000 invoice with a 15% VIP discount and TVA at 19.25%:

```
Subtotal (HT)        100,000
VIP Gold −15%        −15,000
Net HT                85,000
TVA 19,25%            16,363
Total TTC            101,363
```

Computing TVA on the pre-discount 100,000 would overstate the liability on every
VIP invoice — money handed to the DGI that was never collected. This is the
single most important correctness property in the module.

That example is worth keeping as a test fixture, because it lands on an exact
half: 85,000 × 19.25% is 16,362.5. PHP's `round()` goes half away from zero and
gives 16,363, which is what the figures above assume. A language rounding half
to even would give 16,362, so the expected value is stated here rather than
recomputed by whoever writes the test.

### Shape of the change

`Vat::compute` gains an optional document-level discount and returns
`discount_total` alongside the existing figures. The rounding discipline already
in that function is preserved: `net + tax == gross` exactly, no stray minor unit
appearing between a line and its total, and whole francs for XAF.

Document-level rather than per-line, because that is what the chosen
presentation needs and what the print template already renders.

### A gap left open, deliberately

`document_lines.discount_type` and `discount_value` remain unused after this
work. Per-line discounting is a separate job with its own design questions
(interaction with tax rates per line, with converted documents, with credit
notes). Leaving them dead and documented is honest; half-wiring them so that
some discounts work and others silently do not would be worse than the current
state.

---

## 3. Data model

### `vip_tiers`

One row per tier a business offers.

| Column | Notes |
|---|---|
| `id` | ULID |
| `company_id` | tenant scope |
| `name` | "Gold" |
| `price` | what it costs to join |
| `currency` | |
| `period_months` | 12 for annual, 1 for monthly |
| `discount_percent` | 0–100 |
| `perks` | free text, honoured by staff — "free breakfast, late checkout" |
| `is_active` | withdrawn tiers stop being sellable without deleting history |
| `sort_order` | |

### `vip_memberships`

One row per membership sold.

| Column | Notes |
|---|---|
| `id` | ULID |
| `company_id` | tenant scope |
| `contact_id` | who holds it |
| `vip_tier_id` | which tier it came from |
| `tier_name` | **copied at sale** |
| `discount_percent` | **copied at sale** |
| `price_paid` | **copied at sale** |
| `starts_on`, `ends_on` | the term |
| `status` | `active`, `expired`, `cancelled` |
| `document_id` | the invoice that sold it |
| `card_number` | printable, like the loyalty card |
| `verification_token_id` | so the card verifies by QR |
| `cancelled_at`, `cancelled_reason` | |

**Why the tier is copied, not just referenced.** Raising Gold's discount from
10% to 15% next year must not silently rewrite what an existing member was sold,
and lowering its price must not rewrite what they paid. The membership records
the terms it was sold under; the tier is where *future* sales get theirs. This
is the same reasoning that stores an expense's ledger account on the expense
rather than deriving it at report time.

### Indexes

`(company_id, contact_id, status)` for "is this customer a member right now",
and `(company_id, ends_on)` for the expiry sweep.

---

## 4. Lifecycle

### Selling

Selling a membership raises a real invoice through `DocumentIssuer` and takes
payment through `PaymentRecorder` — the same path as any other sale. The money
therefore reaches the ledger, produces a numbered receipt with a verification
QR, and moves the customer's balance, with no second route into the books for
anyone to reconcile.

The membership row is created in the same transaction as the invoice, and holds
its `document_id`. A membership whose invoice was voided is not active.

### Active

A contact has at most one active membership. Buying while already a member
extends from the current `ends_on` rather than from today, so nobody loses the
time they already paid for.

### Expiring

A scheduled command marks memberships whose `ends_on` has passed as `expired`.
The benefit stops; the row stays, so "who lapsed last quarter" has an answer and
those customers can be won back.

Expiry is also checked at the point of use — an invoice raised the day after a
membership lapses gets no discount even if the sweep has not run yet. The sweep
tidies state; it is not what enforces the rule.

### Cancelling

Marks the membership cancelled with a reason. Any refund of the membership fee
goes through `PaymentRefunder`, which already exists — the module does not
invent its own way to give money back.

---

## 5. Applying the discount

When an invoice is raised for a contact with an active membership, the
membership's `discount_percent` is applied as a document-level discount, with
the reason recorded on the document ("VIP Gold −15%").

Three properties:

- **Explicit.** The discount is written onto the document, not recomputed at
  render time. A reprint years later shows what was actually charged, and the
  content hash the QR verifies against covers it.
- **Overridable.** Staff can change or remove it before issuing. The tier
  proposes; the person raising the invoice decides.
- **Frozen at issue.** Once issued, the document is immutable as every other
  issued document is. Changing the tier later does not alter it.

Only invoices and receipts. Quotations may show it as an indication; credit
notes inherit from the invoice they credit.

---

## 6. Module, permissions, surfaces

### Module entry

Registered in `config/modules.php` as `vip`, switchable, **defaulting off**.

This is the one place this module departs from the codebase's documented rule
that every module defaults on. That rule exists so a business discovers a
feature it needs rather than never finding it — but VIP is meaningless without
tiers configured, and a hairdresser has no VIP programme. Defaulting it on would
put an empty screen in every business's navigation. The default is a deliberate
exception and should be called out in the module's own comment.

`requires: ['customers']` — a membership is held by a contact.

### Permissions

New group in `App\Support\Permissions`:

```
'Vip' => ['view', 'manage', 'sell'],
```

- `vip.view` — see tiers and members
- `vip.manage` — create and edit tiers, cancel memberships
- `vip.sell` — sell a membership, which takes money

`sell` is separate from `manage` for the same reason `sales.issue` is separate
from `sales.create`: a receptionist may sign someone up without being able to
redesign the programme.

`VipMembershipPolicy` extends `CompanyScopedPolicy`, following `DealPolicy`.

### Screens

- **Tiers** — list, create, edit, withdraw.
- **Members** — list with tier and expiry, filterable by active/expired; sell;
  cancel; print card.
- **Customer profile** — a VIP panel on the existing contact page, beside
  loyalty.

### API

Under `/api/v1/vip/`, following the established conventions:

| Route | Scope |
|---|---|
| `GET tiers`, `GET memberships`, `GET memberships/{id}` | `read` |
| `POST tiers`, `PATCH tiers/{id}` | `write` |
| `POST memberships` (sells — takes money), `POST memberships/{id}/cancel` | `money` + `idempotent` |

Selling carries `Idempotency-Key` for the same reason payments do: a retry after
a dropped connection must not sell and charge for two memberships.

### Webhooks

`vip.membership.sold`, `vip.membership.expired`, `vip.membership.cancelled`,
through the existing `WebhookDispatcher`.

---

## 7. Testing

The tests that carry weight:

**Tax and money**
- TVA is computed on the discounted base, not the original — asserted with the
  worked example in §2.
- `net + tax == gross` holds with a discount applied.
- XAF totals stay whole francs.
- A membership sale reaches the ledger like any other invoice.

**Correctness over time**
- Raising a tier's discount does not change an existing member's rate.
- Lowering a tier's price does not change what an existing member paid.
- An issued invoice keeps its discount when the tier later changes.

**Lifecycle**
- An expired membership stops discounting, even before the sweep runs.
- Buying while active extends from `ends_on`, not from today.
- A voided membership invoice leaves no active membership.
- Selling twice with one `Idempotency-Key` sells once.

**Boundaries**
- A cashier cannot sell a membership or edit tiers.
- Another company's tiers and memberships 404.
- With the module switched off, every route refuses.

---

## 8. Build order

Each step leaves the system working and tested.

1. **Discounting in `Vat::compute`**, with the tax-base tests. No VIP yet; this
   is a standalone correctness fix and is independently useful.
2. **Tables, models, policy, module entry, permissions.**
3. **`VipMemberships` service** — sell, extend, cancel, expire — over
   `DocumentIssuer` and `PaymentRecorder`.
4. **Discount application** at invoice creation.
5. **Screens.**
6. **API and webhooks.**
7. **Expiry command**, scheduled.
8. **Docs** — `docs/API.md`, and the OpenAPI spec regenerated.

Step 1 ships on its own. Steps 2–4 are the smallest thing worth switching on.

---

## 9. Open questions for later

- Should a VIP member earn Loyalty points at a different rate? Deliberately not
  answered in v1; the modules stay independent until there is a reason.
- Should quotations show the VIP discount before the customer has committed?
- Does a hotel want per-room-type discounts rather than one rate? Revisit when
  the Hotel module is designed — it may be what makes per-line discounting worth
  building.

---

## 10. Next

Hotel Management is the other half of the original request and is deliberately
not designed here. It is a larger subsystem — rooms, rate plans, availability,
bookings, check-in/out, folios, housekeeping — and needs its own spec. Its
booking-and-check-in shape closely resembles the existing Events module
(`ticket_types` with quantity/sold, `tickets` with serial and `checked_in_at`),
which is worth studying before designing it.
