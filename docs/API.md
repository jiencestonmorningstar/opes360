# The Opes360 JSON API

Token-authenticated HTTP access to the same records the web app works on.

Everything here goes through the same three gates the screens do — the tenant
scope, the module switch and the permission catalogue — so **a token can never
do more than the person it belongs to could do while signed in**. There is no
separate "API permission" concept and no service account that bypasses a
business's own settings.

The offline sync endpoints (`/api/sync/v1/*`) are a different thing with a
different contract and are not documented here; they belong to the PWA.

---

## 1. Getting a token

```http
POST /api/v1/tokens
Content-Type: application/json

{ "email": "you@business.com", "password": "…", "device_name": "Kayla's phone" }
```

```json
{ "token": "3|Xk9…" }
```

`device_name` is what the user will see when reviewing what has access, so
name the device rather than the integration version.

Send it on every other request:

```http
Authorization: Bearer 3|Xk9…
Accept: application/json
```

**Throttling.** Six attempts per minute per IP, and the same email+IP lockout
the login form uses (five failures, fifteen minutes). A token endpoint that
throttled more loosely than the login form would just be the easier door.

**Two-factor accounts cannot issue tokens.** Completing a second factor takes
more than one request, and quietly skipping it here would make the API a way
around it. Those accounts get `422` with an explanation and stay on the web
session until a proper challenge flow exists.

### Who am I

```http
GET /api/v1/user
```

Returns the user and, importantly, `current_company_id` — the business every
other request will act on.

### Giving it back

```http
DELETE /api/v1/tokens/current
```

---

## 2. Tenancy, in one paragraph

Every request resolves the caller's **current company** and scopes every query
to it. A record belonging to another business does not 404 because you lack
permission — it 404s because, for that token, it does not exist. This is the
same global scope the web app runs under; the API cannot opt out of it.

To act on a different business, change the user's current company in the app.
One token follows the user, not a company.

---

## 3. Errors

| Status | Meaning |
|---|---|
| `401` | No token, or a revoked one. |
| `403` | Authenticated, but the permission or the module switch says no. |
| `404` | Not found **or** not yours — deliberately indistinguishable. |
| `422` | Validation failed, or a rule of the domain refused (see below). |
| `429` | Throttled. |

`422` carries either Laravel's `errors` object or a plain `message` when a
business rule refused — "Only a won deal can be invoiced.", "An issued document
cannot be deleted."

A suspended business gets `403` with a message, on every endpoint.

---

## 4. Conventions

- Single records come back as `{ "data": { … } }`, lists as `{ "data": [ … ] }`
  with Laravel's `links` and `meta`.
- Lists take `per_page` (1–100, default 25).
- Money is a JSON number in the business's own currency. **The franc has no
  minor unit**, so amounts in XAF are whole; do not assume two decimals.
- Timestamps are ISO 8601. Dates without a time are `YYYY-MM-DD`.
- IDs are ULIDs (strings), except `user` ids which are integers.

---

## 5. Customers and suppliers

`GET /api/v1/contacts` · `POST` · `GET|PATCH|DELETE /api/v1/contacts/{id}`

Filters: `type` (`customer`, `supplier`, `vendor`, `lead`), `q` (name, company
or email), `per_page`.

```json
{ "name": "Boulangerie Nkolbisson", "phone": "+237670000000", "city": "Yaoundé" }
```

Accepted on write: `name`, `type`, `company_name`, `email`, `phone`,
`whatsapp`, `street`, `city`, `country`, `tax_id`, `payment_terms_days`,
`notes`.

A `PATCH` merges: naming three fields leaves the rest as they were.

`tax_id` can be written but is never returned. It is a taxpayer identifier,
encrypted at rest, and nothing has needed to read one back yet — narrowing that
later is cheaper than having handed it out for a year first.

---

## 6. Products and services

`GET /api/v1/items` · `POST` · `GET|PATCH|DELETE /api/v1/items/{id}`

Filters: `type` (`product`, `service`), `q` (name, SKU, barcode), `active`,
`per_page`.

Accepted on write: `name`, `type`, `sku`, `barcode`, `description`, `unit`,
`price`, `cost`, `track_stock`, `reorder_level`, `is_active`.

**Stock is not writable here.** Quantity on hand is the result of movements the
stock ledger records — receipts, sales, transfers, stocktakes. An endpoint that
let you set it directly would put the books and the shelf permanently out of
agreement with nothing explaining why. Use a stocktake.

---

## 7. Sales documents

`GET /api/v1/documents` · `POST` · `GET|DELETE /api/v1/documents/{id}` ·
`POST /api/v1/documents/{id}/issue`

Filters: `type`, `status`, `contact_id`, `outstanding`, `per_page`.

### Draft, then issued

Creating and issuing are separate acts, and this is the part of the API most
worth understanding.

**Issuing** is the moment a document gets its number, freezes its dates, takes
a content hash, receives a verification QR token and enters the books. From
then on it is immutable — because a customer is holding a printed copy, and the
two must never be able to disagree.

So: `POST /api/v1/documents` creates a **draft** with no number. `POST
…/issue` issues it. Pass `"issue": true` on creation to do both in one call,
which is what a till wants.

```json
{
  "type": "invoice",
  "contact_id": "01j…",
  "issue": true,
  "lines": [
    { "description": "Ciment 50kg", "quantity": 10, "unit_price": 6500 }
  ]
}
```

TVA is computed server-side by the same pass the screens use — do not send tax
figures, they are ignored. Line totals come back computed.

Creating needs `sales.create`; issuing needs `sales.issue` as well, whether it
happens through `"issue": true` or the separate endpoint. A business that lets
a junior draft an invoice but not commit one gets that, unchanged, here.

There is **no update route**. An issued document cannot be edited, and a draft
that needs different lines can be deleted and recreated.

`DELETE` works on drafts only; an issued document must be voided, which is not
yet exposed here.

---

## 8. Sales pipeline

`GET /api/v1/deals` · `POST` · `GET|PATCH|DELETE /api/v1/deals/{id}`

Filters: `stage`, `open`, `per_page`.

Stages, in order: `lead`, `qualified`, `proposal`, `won`, `lost`.

A deal needs **either** `contact_id` **or** `lead_name` — a lead is usually a
name and a phone number long before it is worth a customer record, and forcing
one is how the customer book fills with junk.

### Moving a deal

```http
POST /api/v1/deals/{id}/move
{ "stage": "lost", "lost_reason": "Bought from a competitor" }
```

Its own endpoint rather than a field on `PATCH`, because it is the action a
board actually performs and it carries rules a general update should not:
`closed_at` is set on entering `won`/`lost` and **cleared on reopening**, and
`lost_reason` is dropped when a deal stops being lost.

### Turning a won deal into an invoice

```http
POST /api/v1/deals/{id}/invoice
```

Creates a **draft** invoice, links it to the deal, and — if the deal was only
ever a lead — creates the customer record at the same time, which is the
natural moment for it.

Draft, not issued: nothing here knows the real line items, quantities or tax
treatment; it has one figure somebody typed on a card. A person checks it
before it becomes paper.

Requires `sales.create` **as well as** the deal permission: it writes into the
sales module. Refused with `422` if the deal is not `won`, or already invoiced.

---

## 9. Importing records

`POST /api/v1/imports/preview` · `POST /api/v1/imports`

Multipart, with `type` (`customers` or `products`) and `file`.

Accepts **.xlsx, .xls and .csv**. The file is sniffed by content, not trusted
by its extension — a workbook named `.csv` is a common way this goes wrong.

**Preview writes nothing.** It reports what it found, which columns it matched,
which it ignored, and which rows it would skip and why. An import that writes
first and reports afterwards leaves a business picking half-right rows out of
its customer book by hand.

```json
{
  "data": {
    "rows": [ … ],
    "skipped": [ { "line": 7, "reason": "Same name as line 3." } ],
    "matched": ["Nom", "Telephone", "Ville"],
    "unmatched": ["Loyalty tier"]
  }
}
```

Column names are matched loosely and in French as well as English — `Nom`,
`Téléphone`, `Prix de vente` all land where you would expect. Only the name
column is required.

Re-running an import **updates rather than duplicates**: products match on SKU
then name, customers on email then name.

Limits: 2,000 rows and 5 MB per import.

---

## 10. Payments

`GET /api/v1/payments` · `POST` · `GET /api/v1/payments/{id}`

Filters: `contact_id`, `method`, `from`, `to`, `per_page`.

Recording a payment does the whole thing in one transaction — the payment, its
allocation, the invoice's balance and status, a **numbered receipt** with its
content hash and verification token, and the customer's cached balance. That is
why there is a service behind it rather than a row insert: a payment written
directly would be money the books never saw and a receipt nobody can check.

```json
{ "document_id": "01j…", "amount": 50000, "method": "cash" }
```

Methods: `cash`, `bank_transfer`, `mobile_money`, `card`.

`received_at` is accepted for money that changed hands earlier — a business
entering last week's cash on Monday. Send it rather than correcting the date
afterwards: the receipt's hash covers it, so a later edit produces a receipt
that fails its own QR verification.

Refused with `422`: paying a draft or voided document, and **overpaying**.
Customer credit is a real feature, not a negative balance nobody meant to
create.

The response carries `receipt.verification_token` — the same token the app's
QR is built from, so a caller printing its own copy prints a checkable one.

There is no update or delete. A payment is a thing that happened; correcting it
is a refund or a void.

---

## 11. Expenses

`GET /api/v1/expenses` · `POST` · `GET /api/v1/expenses/{id}` ·
`POST /api/v1/expenses/{id}/settle` · `POST /api/v1/expenses/{id}/void`

Filters: `status`, `category`, `supplier_id`, `from`, `to`, `per_page`.

One shape covers both a supplier bill and a direct expense, because the
difference is a due date rather than a kind of thing.

**`vat_rate` is a fraction, not a percentage.** `0.1925`, not `19.25` — the
latter would be a 1,925% expense, so it is rejected.

`category` must be one of the SYSCOHADA expense categories; the account it maps
to is stored on the expense rather than derived at report time, so
recategorising the list later cannot rewrite what a past month was posted
against.

`settle` pays a bill in part or in full and refuses more than is owing. `void`
cancels one recorded in error — **voided, not deleted**, with its journal entry
reversed rather than removed, because "what did the books say in March" has to
keep having an answer. That is why there is no delete route.

---

## 12. The books

`GET /api/v1/accounting/accounts` · `trial-balance` · `income-statement` ·
`balance-sheet` · `journal`

All take `from` and `to`; `journal` also takes `journal` (the journal code).

**Read only, and not as a limitation to be lifted.** Every entry in this ledger
is the consequence of a business event — an invoice issued, a payment taken, an
expense settled, a payroll month approved — and each of those already has its
own endpoint that posts as a side effect. An endpoint that let you write a
journal entry by hand would be a way to make the books disagree with the
documents underneath them, with nothing to say why.

Figures come from the same service the accounting screens and exports read, so
a report pulled over HTTP and one printed from the app cannot differ.

Requires `accounting.view`.

---

## 13. Staff and payroll

`GET /api/v1/employees` · `POST` · `GET|PATCH /api/v1/employees/{id}`

Filters: `status` (`active`, `suspended`, `ended`), `department`, `q`,
`per_page`.

The national id, CNPS and NIU numbers, bank account and emergency contact are
**not returned**. They are the identity-theft-shaped fields in this table,
`employees.view` is held by every manager, and nothing has needed to read them
back over HTTP yet. Pay is not here either — it lives behind the payroll
permissions.

`GET /api/v1/payroll/runs` · `GET /api/v1/payroll/runs/{id}/payslips`

Read only. Running a month, approving it and marking it paid are deliberately
absent: approving commits the business to a month's wages and to the CNPS and
IRPP declarations that follow. The role catalogue already keeps
`payroll.approve` away from the accountant who runs it, and a signature is not
a thing to hand to a token.

Payslips are scoped to a run rather than offered as a flat list, because "every
payslip this business has ever produced" is a question with no honest use and a
very obvious dishonest one.

---

## 14. What is not here yet

Events and ticketing, forms, loyalty and the partner programme have screens but
no API. They will follow the pattern above as each is next touched.

Also absent by design, not by omission: voiding a sales document, refunding a
payment, and anything that posts to the ledger by hand.

`PATCH /api/v1/deals/{id}` accepts a `stage` and applies the same closure rules as
`/move`, so neither can leave `closed_at` disagreeing with the stage. Prefer
`/move` anyway: it is the action a board performs, and `lost_reason` belongs
with it.
