# The Opes360 JSON API

Token-authenticated HTTP access to the same records the web app works on.

Everything here goes through the same three gates the screens do — the tenant
scope, the module switch and the permission catalogue — so **a token can never
do more than the person it belongs to could do while signed in**. There is no
separate "API permission" concept and no service account that bypasses a
business's own settings.

The offline sync endpoints (`/api/sync/v1/*`) are a different thing with a
different contract and are not documented here; they belong to the PWA.

**Base URL:** `/api/v1`. The version is in the path so that a future breaking
change can be made without breaking you — a v2 will live beside v1, not on top
of it.

**Machine-readable:** an OpenAPI 3.1 description is served at
[`/openapi.json`](/openapi.json), generated from the router by
`php artisan opes:export-openapi`. It is generated rather than hand-written for
the same reason the install schema is: a spec maintained by hand drifts, and a
spec that lies is worse than none — a client generated from it fails in ways
that look like server bugs.

---

## 0. Tokens and scopes

Create tokens in the app under **Settings → API tokens**. That is the right way
to get one: a token is revocable and can be narrowed, and it lets you give an
integration access without handing it the password to the whole account.

A token may be **narrower** than the person who created it, never wider:

| Scope | What it opens |
|---|---|
| `read` | Lists and single records, across the business |
| `write` | Add and change customers, products, deals and documents |
| `money` | Record payments, enter and settle expenses, sell a ticket or a membership |
| `people` | Read the staff file and payroll |

They do not imply each other. A reporting token asking for `read` does not
thereby get the payroll, and a token that can add a customer cannot take a
payment. A token minted with no scopes at all holds them all, which keeps a
one-off script simple.

Scopes are a ceiling on top of the permission catalogue, not a replacement for
it — every scope there is, held by a cashier, is still a cashier.

**Rate limit:** 120 requests a minute, counted per token.

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

`DELETE` works on drafts only.

### Undoing a sale

Since an issued document cannot be edited, these are how a mistake is
corrected. All three accept an `Idempotency-Key`.

`POST /api/v1/documents/{id}/void` — cancels it. The verification token is
revoked so a printed copy stops verifying, and the ledger entry is reversed
rather than removed, so March still has an answer. **Refused while payments sit
against it**: money already taken has to be dealt with first, or a receipt ends
up pointing at a document saying nothing was ever owed.

`POST /api/v1/documents/{id}/credit-note` — takes an `amount`, and is the
answer for an invoice that *has* been paid. The money is acknowledged as not
owed rather than the original being rewritten to pretend it was never charged.

`POST /api/v1/documents/{id}/convert` — quotation → invoice, proforma →
invoice. Needs the issue right rather than merely the right to draft, because
it produces a permanent numbered document.

Voiding is its own permission (`sales.void`): a Sales Officer may raise an
invoice without being able to make one disappear.

To give money back on a payment rather than to cancel the sale, see
**Refunds** in the Payments section.

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
is a refund.

### Refunds

`POST /api/v1/payments/{id}/refund` — requires `payments.refund` and the
`money` scope. Accepts an `Idempotency-Key`.

```json
{ "amount": 25000, "method": "mobile_money", "reason": "Goods returned" }
```

**The payment is not deleted and its receipt keeps verifying.** The customer is
holding a printed copy saying money changed hands, and it did — deleting the
payment would leave that copy pointing at nothing, which is the exact situation
the verification QR exists to prevent. A refund is a second event recorded
beside the first.

What it unwinds, in one transaction:

- the allocation, so the invoice becomes **owed again** and returns to `partial`
  or `issued` (a voided document stays void — refunding against something
  already cancelled must not bring it back to life);
- the customer's cached balance, recomputed from the documents rather than by
  arithmetic on this one;
- the ledger — a full refund reverses the settlement entry, a partial one posts
  its own, because reversing the whole entry would credit the till with money
  that never left it;
- loyalty points earned on the payment, in proportion, through the audited
  adjustment path and never below zero.

`method` is how the money went **back**, which need not be how it came in: cash
taken at the counter is often returned by mobile money.

`reason` is **required**. A refund nobody can explain a year later is the entry
in the books that matters most and reads least.

Partial refunds are allowed and may be repeated, but they cannot add up past
what was paid.

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

## 14. Events and ticketing

`GET /api/v1/events` · `GET /api/v1/events/{id}` ·
`GET /api/v1/events/{id}/ticket-types` · `GET /api/v1/events/{id}/tickets` ·
`POST /api/v1/events/{id}/tickets` ·
`POST /api/v1/events/{id}/tickets/{ticket}/check-in`

Filters on the event list: `status` (`draft`, `published`, `cancelled`),
`upcoming`, `q` (title or venue), `per_page`. On the attendee list: `status`
(`issued`, `checked_in`, `void`), `q` (serial, buyer name or email), `per_page`.

**Events are read only.** An event is a poster — a title, a venue, a date and a
price list somebody writes once and checks on a screen before sharing a link to
it. Nothing about it repeats and nothing about it arrives from another system,
while getting the date wrong is a mistake the public page prints. What
integrations want from this module is to sell through it and scan at the door,
and those are the two things you can do.

`remaining` on a ticket type is `null` when the type is unlimited, not a large
number. "None left" and "no limit" are different answers.

### Selling

```http
POST /api/v1/events/{id}/tickets
{
  "buyer_name": "Marie Ngo",
  "buyer_phone": "+237670000000",
  "quantities": { "01j…": 2 }
}
```

`quantities` is keyed by ticket type id, so one call issues a whole order.
Either `buyer_email` or `buyer_phone` is required — the tickets have to reach
somebody. Maximum ten of any one type per call.

Returns **201** with one object per ticket, each carrying its own `serial` and
`verification_token`. Tickets are rows rather than an order with a quantity
because two seats bought together still admit two people separately, and each
needs its own QR. A caller printing its own copy prints a checkable one.

This runs through the same service the public sales page does, in one
transaction with the ticket-type rows locked. That is the oversell guard: a
four-seat order that fails on the fourth leaves nothing behind, and two buyers
racing for the last seat cannot both get it. Refused with `422` when a type
lacks availability, when sales have closed (an event is `draft`, `cancelled`,
or has already started), or when a ticket type belongs to another event.

Under the **`money` scope** and idempotent: a seat is value, and a retry after
a dropped connection must not issue the order twice. Send an `Idempotency-Key`.

Issuing needs `events.create`. The catalogue has no separate "sell" action, so
a cashier — who may scan at a door — cannot issue over the API. That is the
line the role catalogue already draws, not a new one.

Tickets sold this way are **not marked paid**. Whether the money arrived is a
separate fact, and marking it is done on the screen where somebody can see the
list.

### The door

```http
POST /api/v1/events/{id}/tickets/{ticket}/check-in
```

Under the **`write` scope**, not `money`: nothing changes hands at a door, so a
scanner can hold a token that admits people and cannot sell a thing. Needs
`events.check-in`.

Nested under the event on purpose — a serial from another night `404`s rather
than quietly admitting somebody to the wrong one.

A second check-in is refused with `422` and the time of the first. The screen
no-ops because the person using it can see the row; a scanner cannot, and
"already used at 20:14" is the one answer a door needs. Voided tickets are
refused too.

**Issued tickets cannot be deleted here.** A ticket that should not have been
sold is voided on the screen, which keeps the row, its serial and its QR — a
serial that simply vanished would make an honest buyer at the door
indistinguishable from a forged one.

---

## 15. Forms

`GET /api/v1/forms` · `GET /api/v1/forms/{id}` ·
`GET /api/v1/forms/{id}/responses`

Filters: `status` (`draft`, `open`, `closed`), `q`, `per_page`. Responses take
`from`, `to` and `per_page`.

**Creating a form over HTTP is not offered.** A form is a set of ordered field
definitions — ids, types, options, required flags — that the builder edits as a
whole and the public page renders. Posting that JSON blind is strictly harder
than dragging four fields on a screen, and nothing about it repeats or arrives
from elsewhere. What integrations want from this module is the data coming
back, which is what these endpoints are.

`fields` comes back normalised, exactly as the builder and the public page see
it. **Answers stay keyed by field id**, not by label: that is the whole reason
for the storage format, since renaming a field must never rewrite what somebody
already submitted. Join against `fields` if you want labels.

Reading responses needs **`forms.responses`**, a separate grant from seeing
that a form exists. Submissions are other people's names, numbers and
complaints, and the business decides who reads them.

Responses are scoped to a form rather than offered as a flat list, for the same
reason payslips are scoped to a run.

---

## 16. Loyalty

`GET /api/v1/loyalty/contacts/{contact}` ·
`GET /api/v1/loyalty/contacts/{contact}/transactions` ·
`POST /api/v1/loyalty/contacts/{contact}/redeem`

Points hang off a customer rather than standing alone — a balance is part of
somebody's record — so both the contact and the loyalty ability are checked.
Reading needs `customers.view` **and** `loyalty.view`.

```json
{
  "data": {
    "contact_id": "01j…",
    "card_number": "LOY-7QK2M4XZ",
    "points": 500,
    "point_value": 1,
    "value": 500,
    "currency": "XAF",
    "program_enabled": true
  }
}
```

`value` is what the points are worth today, in the business's own currency —
the number a till takes off a bill. It is computed server-side because the rate
is a setting that can change, and a client caching its own copy would
eventually discount by last month's.

`transactions` is the ledger behind that balance, newest first. `points` is
signed (earns +, redemptions −) and `balance_after` is the balance that row
produced, both stored rather than derived, so a caller paging backwards sees
the numbers the till printed at the time.

### Redeeming

```http
POST /api/v1/loyalty/contacts/{contact}/redeem
{ "points": 200, "note": "Remise en caisse" }
```

Returns **201** with the ledger row. Needs `loyalty.redeem`, and sits under the
**`money` scope** with idempotency: a point is a discount the business will
honour, and a retry must not deduct twice.

Refused with `422` when the balance is short, and the message names the
customer and the number actually available — which is what the person at the
till has to say out loud. It is **refused, not clamped**: the check happens
inside the service under a row lock, because two tills redeeming the last
hundred points at the same moment both pass a check made against their own
stale copy, and clamping the loser to zero would hand out the reward twice and
leave a ledger balancing to a number nobody ever had.

**Earning is not exposed, and neither is adjusting.** Points are earned as a
side effect of a payment, which already has an endpoint; an "earn" route would
be a way to mint them with no spend behind them. A manual adjustment is audited
in the service — it demands a note and records who made it — but it is the
goodwill gesture a manager makes in front of a customer, with a screen and a
name attached. Nothing has asked to do that over HTTP, and handing a token the
ability to conjure balances is not a default to ship ahead of a use case.
Issuing a loyalty card is absent for the same reason: it happens once, at a
counter, with the customer standing there.

---

## 17. Webhooks

`GET /api/v1/webhooks` ·
`POST /api/v1/webhooks` ·
`GET /api/v1/webhooks/{id}` ·
`PATCH /api/v1/webhooks/{id}` ·
`DELETE /api/v1/webhooks/{id}` ·
`GET /api/v1/webhooks/deliveries` ·
`POST /api/v1/webhooks/deliveries/{id}/redeliver`

Everything above this section is pull. An integration that wants to know when
an invoice is issued has one option — ask again, and keep asking — which on a
metered connection costs it 1,440 requests a day to learn about four sales, and
still learns about each of them up to a minute late.

A webhook inverts that. Register a URL, name the moments you care about, and we
POST to it when one happens.

Managing endpoints sits under the **`write` scope** and needs
**`webhooks.manage`**, which only an Owner or an Administrator holds. That is
not caution for its own sake: an endpoint subscribed to `payment.recorded` is a
standing copy of everything the business sells, for as long as nobody notices
it. Reading the delivery log needs `webhooks.view`, under the `read` scope.

### Registering one

```http
POST /api/v1/webhooks
{
  "url": "https://example.com/hooks/opes360",
  "description": "Stock system at the warehouse",
  "events": ["document.issued", "payment.recorded"]
}
```

Returns **201**. The response contains a `secret`, and it is the **only**
response that ever will — every read omits it. We keep the secret in the clear
rather than hashed, unlike an API token, because both ends need the same bytes
to compute the same HMAC and there is nothing to compare a hash against. Lose
it and you replace the endpoint.

`url` must be **https**. The signature below proves who sent a delivery; it
does nothing to hide what is in it, and what is in it is the business's
takings.

### The events

| Event | Fires when |
| --- | --- |
| `document.issued` | An invoice, quotation or other document is issued — the moment it gets its number and enters the books. |
| `document.voided` | An issued document is cancelled. |
| `payment.recorded` | A customer payment is recorded and its receipt issued. |
| `expense.recorded` | A supplier bill or an expense is entered. |
| `deal.won` | A deal is moved to the won stage. |
| `contact.created` | A customer or supplier is added, from the screen or the API. |

The list is short on purpose. An event earns a place here when a business could
say what it would *do* about it; a message for every row that saves produces a
stream nobody can act on, and a `contact.updated` that fires because somebody
fixed a typo in a phone number.

Two absences are deliberate. A **bulk import** creates contacts without firing
`contact.created` — two thousand rows would mean two thousand deliveries, which
is a denial of service dressed as a feature. And the placeholder contact a won
deal conjures on its way to an invoice does not fire it either: that is a side
effect of invoicing, not somebody adding a customer.

### What arrives

```http
POST /hooks/opes360
Content-Type: application/json
Opes-Event: payment.recorded
Opes-Delivery: 01j8…
Opes-Signature: t=1755000000,v1=8f4b1c…

{
  "id": "01j8…",
  "event": "payment.recorded",
  "created_at": "2026-08-17T09:14:22+00:00",
  "company_id": "01h…",
  "data": { "…": "…" }
}
```

`id` is the delivery id, and it is stable across retries and across a manual
redelivery. **Deduplicate on it.** A retry that crosses with your own slow
success is not hypothetical; it is the one failure mode at-least-once delivery
guarantees you will eventually see.

Answer **2xx**, and answer quickly. We wait five seconds for a connection and
ten seconds in total, then treat the delivery as failed. A receiver that needs
longer should answer 200 first and do its work afterwards.

### Checking the signature

Every delivery carries an HMAC-SHA256 of the body, keyed on your secret:

```
Opes-Signature: t=<unix timestamp>,v1=<hex digest>
```

The signed material is **the timestamp, a dot, and the exact bytes of the
body** — `"{t}.{body}"`. The timestamp is inside the signature on purpose.
Signing the body alone would produce a proof that stays valid forever, and
anybody who captured one delivery — from a log, a mirror, a misconfigured
proxy — could replay it a month later and have it verify perfectly, because it
*is* genuine. It just is not now. With the timestamp signed, a delivery's age
cannot be changed without the secret, so refusing anything old kills the replay.

Verify against the **raw request body**, before any JSON decoding. Decoding and
re-encoding reorders keys and changes whitespace, and the digest will then not
match for reasons that look like our bug and are not.

```php
<?php

// $header is the Opes-Signature header, $body the raw request body, and
// $secret the value shown once when you registered the endpoint.
function opes_webhook_is_genuine(string $header, string $body, string $secret): bool
{
    $parts = [];

    foreach (explode(',', $header) as $piece) {
        [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, null);
        $parts[$key] = $value;
    }

    if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
        return false;
    }

    // Anything older than five minutes is a replay, or a clock nobody has
    // pointed at NTP. Either way, refuse it.
    if (abs(time() - (int) $parts['t']) > 300) {
        return false;
    }

    $expected = hash_hmac('sha256', $parts['t'].'.'.$body, $secret);

    // hash_equals, not ===. A comparison that returns early leaks how much of
    // a guess was right, which is enough to forge a digest one byte at a time.
    return hash_equals($expected, $parts['v1']);
}

$body = file_get_contents('php://input');
$header = $_SERVER['HTTP_OPES_SIGNATURE'] ?? '';

if (! opes_webhook_is_genuine($header, $body, getenv('OPES_WEBHOOK_SECRET'))) {
    http_response_code(401);
    exit;
}

$event = json_decode($body, true);
// $event['id'] — deduplicate on this. $event['event'] — what happened.
http_response_code(200);
```

Worked through, with a secret of `whsec_test`, a timestamp of `1755000000` and
a body of exactly `{"id":"01j","event":"deal.won"}`, the signed material is

```
1755000000.{"id":"01j","event":"deal.won"}
```

and `hash_hmac('sha256', $material, 'whsec_test')` is what arrives after `v1=`.
Change one byte of the body, or one digit of the timestamp, and it does not
match.

The URL alone proves nothing: it leaks the moment it appears in a proxy log or
a screenshot, and anybody holding it can post whatever they like to it. **A
receiver that does not check the signature has no webhook security at all.**

### Retries

A delivery that does not answer 2xx is retried on a fixed schedule:

| Attempt | Sent |
| --- | --- |
| 1 | immediately |
| 2 | 1 minute later |
| 3 | 5 minutes later |
| 4 | 30 minutes later |
| 5 | 2 hours later |
| — | and a last one 6 hours after that |

Five attempts spanning a little under nine hours, after which the delivery is
marked `failed` and left alone. The shape matters more than the numbers: the
first retry is quick because most failures are a dropped connection or a server
mid-restart, and the last is far away because a failure that has survived two
hours is an outage somebody has to fix rather than one that will pass.

Retrying for days instead would be the dishonest option. An event delivered a
day late is rarely worth having, and the delivery log plus a manual redelivery
is a better answer than an infinite queue nobody is watching.

### When an endpoint switches itself off

Fifteen **whole deliveries** failing in a row — each of which has already
exhausted its own five attempts across nine hours — switches the endpoint off.
`is_active` goes false, `disabled_at` is stamped, and `disabled_reason` records
what the last error was, so the answer to "why did my webhooks stop" is on the
settings screen rather than in our logs.

Fifteen rather than three, because an endpoint is not dead just because its
server restarted during a deploy, and a business whose integration switched
itself off over a two-minute outage has been handed a worse problem than the
one this feature solves. A single bad delivery does not count as five failures
either: the counter moves when a delivery is abandoned altogether, not on every
attempt.

`PATCH` with `"is_active": true` turns it back on and clears the counter — an
endpoint that has been fixed must not switch itself off again on its very next
hiccup, which would read as the fix not having worked.

### The delivery log

```http
GET /api/v1/webhooks/deliveries?endpoint_id=01j…&status=failed
```

Returns what we sent, what came back and what went wrong: `payload`,
`attempts`, `response_status`, a truncated `response_body`, `last_error`,
`delivered_at` and `next_attempt_at`. It exists to answer the one question this
feature reliably generates — "you say you sent it, my system never got it" —
and an answer that omitted the body would not answer it.

`POST /api/v1/webhooks/deliveries/{id}/redeliver` sends a failed one again,
with the body it originally carried and the same delivery id. Not rebuilt from
the record as it stands today: the record may have changed since, and a
"redelivery" quietly carrying newer data would make your history disagree with
ours in a way neither side could see.

### What a webhook can never do

Break the thing it describes. Every dispatch is queued after the business
transaction commits, so an unreachable endpoint, a full disk or a queue that is
down cannot roll back a payment somebody has already taken at a counter. The
other half of that promise holds too: nothing is sent for something that did
not happen, because a transaction that rolls back never reaches the dispatch at
all.

---

## 18. The secretariat programme

`GET /api/v1/partners/clients` · `POST` · `GET|PATCH /api/v1/partners/clients/{id}`
`GET /api/v1/partners/earnings` · `/commissions` · `/payouts` ·
`POST /api/v1/partners/payouts`

Client filters: `q` (name, contact or phone), `converted`, `per_page`.

**Every route here answers `403` for a business that is not a secretariat, and
that is not a role decision.** The programme is a property of the account: a
plain business has no client book to manage and no balance to withdraw, so
every `partners.*` ability is denied before any role is consulted. The Owner of
an ordinary business is refused exactly as a cashier is.

`GET /partners/earnings` returns what has been earned, what has been charged in
card fees, what has already been withdrawn, the balance, and the minimum a
payout needs. It comes from the same service the earnings screen reads, so a
figure pulled over HTTP and one shown in the app cannot differ.

### Asking to be paid

```json
{ "method": "mtn", "destination": "+237670000000" }
```

**The amount is not a parameter.** It is recomputed from the ledger at the
moment of the request, because a balance a client read a few minutes ago is not
a promise and the figure a partner is paid has to be the one the ledger says
now. It also removes the obvious attack: an amount a caller can name is an
amount a caller can inflate. Below the minimum, the request is refused with the
balance and the minimum in the body.

Takes an `Idempotency-Key`. A payout empties the balance, so a retry that
created a second request would ask to be paid twice for the same earnings.

Adding a client is `write`; asking to be paid is `money`. Putting a name in a
book and moving money are not the same trust.

The invite token a client is enrolled with is **never returned**. It is a
capability — whoever holds it can claim the referral — so a list endpoint that
handed one out per row would turn a read scope into a way to redirect somebody
else's commission.

---

## 19. VIP membership

`GET /api/v1/vip/tiers` · `POST` · `PATCH /api/v1/vip/tiers/{id}`
`GET /api/v1/vip/memberships` · `GET /api/v1/vip/memberships/{id}` ·
`POST /api/v1/vip/memberships` · `POST /api/v1/vip/memberships/{id}/cancel`

A customer buys a **tier** for a term, and every invoice raised for them while
it runs carries an automatic discount.

**This module ships switched off.** Every other module defaults on so a business
discovers what it needs, but VIP is meaningless until somebody configures a
tier and most businesses have no membership programme. Switch it on in
Settings → Modules; until then every route here answers `403`.

### Tiers

```json
{ "name": "Gold", "price": 50000, "period_months": 12, "discount_percent": 15 }
```

`perks` is free text. It is honoured by staff at the counter and **not enforced
by the system** — do not build logic on it.

**Editing a tier changes what future sales get, never what a member already
bought.** A membership carries its own copy of the name, discount and price, so
raising Gold's rate next year does not rewrite what somebody was sold today.
That is why `vip.memberships` reports the membership's terms rather than
reading through to the tier.

Tiers are withdrawn (`is_active: false`) rather than deleted, so the answer to
"what was Gold, back then" survives.

### Selling

```json
POST /api/v1/vip/memberships
{ "contact_id": "01j…", "tier_id": "01j…" }
```

Selling **raises a real invoice** for the fee through the ordinary sales path,
so the money reaches the ledger, gets a number and a verification QR, and moves
the customer's balance like anything else the business sells. The response
carries `document_id` so you can follow it.

Because it takes money it sits under the `money` scope and accepts an
`Idempotency-Key`: a retry after a dropped connection must not sell and charge
for two memberships.

**Buying while already a member extends the term rather than restarting it** —
the new term begins the day after the current one ends, so nobody loses days
they have paid for.

The tier's own discount does **not** apply to the membership fee. What a tier
grants is a discount on what the member buys afterwards.

### The discount reaching an invoice

Nothing extra is needed. `POST /api/v1/documents` applies an active member's
rate automatically and returns it as `discount_total`.

The tier proposes and the caller decides: send `discount_percent` to override
it, or `0` to remove it. **TVA is computed on the discounted base** — see §4.

`is_active` and the discount are decided by the **dates**, not by the `status`
column. A membership that lapsed last night gives no discount this morning even
though the nightly sweep has not run yet.

### Cancelling

`reason` is required. Cancelling stops the benefit and keeps the record, and
sits under `write` rather than `money` because it stops a benefit rather than
moving money. Refunding the fee, where one is owed, is a separate act through
`POST /api/v1/payments/{id}/refund`.

### Events

`vip.membership.sold`, `vip.membership.cancelled`, `vip.membership.expired`.
The `expired` event names the membership that lapsed rather than reporting a
count, so a subscriber can act on it.

---

## 20. Branding

A business can re-skin the platform: two seed colours, a background tone, a
corner radius, a spacing density, and an optional liquid-glass surface
treatment. The same palette drives the app, the printed documents, the loyalty
and VIP cards, and the customer-facing pages.

```
GET    /api/v1/branding      read   business.view
PUT    /api/v1/branding      write  business.manage-branding
PATCH  /api/v1/branding      write  business.manage-branding
DELETE /api/v1/branding      write  business.manage-branding
```

### Paint with the palette, not the inputs

The response carries both, and the distinction matters:

```json
{
  "data": {
    "inputs":  { "primary": "#1db954", "radius": "rounded", "skin": "solid" },
    "palette": {
      "light": { "--color-brand": "#0f7038", "--color-fill-brand": "#0f7038" },
      "dark":  { "--color-brand": "#3fce6f" },
      "root":  { "--spacing": "0.25rem", "--radius-card": "1rem" }
    },
    "options": { "radius": ["sharp", "soft", "rounded", "pill"] }
  }
}
```

`inputs` is what the owner chose. `palette` is what to render with. They differ
on purpose.

A brand colour is a signature, not a text colour. Spotify's green scores 2.30
against this platform's page background — less than half the 4.5:1 the WCAG AA
standard asks for — which is why Spotify itself puts black text on its green
button and never sets green type on white. So the seed is kept exactly where it
is safe, and the roles that carry text are derived from it: same hue, same
chroma, lightness moved in OKLCH until the contrast target is met.

The near-misses are the reason to take this seriously rather than the obvious
failures. Stripe's indigo and Shopify's green are both perfectly legal as
buttons and both illegal as body text here, by 4.18 and 4.39 against a 4.5
floor. That is a margin nobody detects by eye, and a client that paints with
`inputs.primary` will ship it.

### Partial updates

Send only what you are changing:

```
PATCH /api/v1/branding
{ "radius": "pill" }
```

The keys you leave out keep their current values. `DELETE` resets everything to
the Opes360 default — a different statement from setting the colours to nothing.

### What cannot be branded

`positive`, `warning` and `negative` are fixed. A business cannot make "overdue"
green. Those three mean the same thing in every company on the platform, and a
bookkeeper working across two of them has to be able to trust that.

Typography is not brandable either, and glass is applied only to menus and bars
— never to a card showing a figure, because a total's legibility must not depend
on what happens to be behind it.

## 21. Approvals

Workflows are defined in the product, not over the API — whoever can edit one
can write themselves a path with no approver in it, and that is not something
a token should be able to do. What the API exposes is the running state and
the outcome.

`GET /api/v1/approvals` — instances for the current company. Filter by
`status` (`running`, `approved`, `rejected`, `changes_requested`, `stalled`,
`cancelled`), by `subject_type`, or by `subject_id`.

`GET /api/v1/approvals/{instance}` — one instance, with its decisions in
order. Each decision carries the step name **as it was when the decision was
made**, so renaming a workflow afterwards does not rewrite history.

`GET /api/v1/approvals/mine` — what is waiting on the token's user. The same
list `/actions` shows.

`POST /api/v1/approvals/{instance}/decisions` — act on an assignment.

```json
{ "action": "approved", "comment": "Within budget." }
```

`action` is one of `approved`, `rejected`, `changes_requested`. The engine
refuses a decision from anybody without a pending assignment on that instance:
being asked is the permission, and there is no separate ability to grant.

Not here, and deliberately: defining or editing a workflow, and delegating.
Delegation names a colleague, which is a person-to-person act rather than an
integration's.

See `docs/workflows.md`.

## 22. Library (Documents)

The Documents module — Module 13's generator plus the filing layer around it.
`GET /api/v1/library` lists what the token's user can see; a restricted
document a user cannot open never appears, is never returned by
`GET /api/v1/library/{document}`, and 403s rather than 404s if requested
directly — the policy is the same one the screen uses.

`POST /api/v1/library` composes a document from a template. Uploading a file
is a browser action (the Papers screen) and is not on this endpoint.

`PUT /api/v1/library/{document}` accepts **filing fields only** — `kind`,
`security`, `tags`, `folder_id`, `department_id`, `owner_id`, `expires_on`.
Never title, body or recipient. This is deliberate and enforced twice: the
model refuses any other field on an issued document regardless of what is
sent, and the endpoint authorises through a `file` ability separate from
`update` — `update` requires a draft, because it is content; filing is not
content, and has to work on an issued document or it is useless for the
documents that most need managing.

`POST` / `DELETE /api/v1/library/{document}/relations` attach or remove a
link to an ERP record — `contact`, `employee`, `document` (an ERP sales
document) or `project`. No document is ever copied into this table; a
relation only records what a document is about. The related type is a closed
list, not an arbitrary class name, so a token cannot attach a document to a
model with no tenant scope.

Linking requires `papers.share`, not `papers.manage` — attaching a document to
a customer sends it somewhere beyond a straightforward read, the same
reasoning that already separates `share` from `view`.

`content_hash` is never returned by any endpoint here. It exists to detect
tampering, not to be handed to whoever is checking.

### Versions

`GET /api/v1/library/{document}/versions` — every version, oldest first.

`GET /api/v1/library/{document}/versions/compare?from=1&to=3` — a word-level
diff of `title`, `recipient` and `body` between two version numbers. Each
field reports whether it changed and the diff itself as a sequence of
`kept`/`added`/`removed` tokens, in order — reassembling the `kept` and
`added` tokens reconstructs the newer text; `kept` and `removed` reconstructs
the older one.

`POST /api/v1/library/{document}/versions/{version}/restore` — replace the
document's current content with a past version's. Refused with 403 for an
issued document — the same `update` ability content edits use, which already
requires a draft, catches this before the service beneath it ever runs.
Restoring does not delete anything: it adds a new version on top, so the
history still shows exactly what happened and in what order.

### Activity

`GET /api/v1/library/{document}/activity` — one chronological timeline:
created, filed, revised, issued, commented, replied — newest first. Built
from the document's own version history, its comments, and the audit log
already kept on it; there is no separate table for this, so nothing here can
disagree with the records it is describing.

### Dynamic fields

Beyond what a caller fills in explicitly, `company.name`, `company.address`,
`company.email`, `company.phone` and `today` are always available to a
template — the same fields `DocumentComposer` has always exposed, now sourced
from an extensible field registry rather than hard-coded.

`customer.name`, `customer.email`, `employee.name`, `employee.job_title`,
`employee.department`, `project.name` and `project.code` are available when
the composing party already has the relevant record in hand and passes it as
context — `merge()`'s fourth, optional argument. No API endpoint accepts this
context yet; it exists at the service layer for a module (or a future
"create from this customer" screen, §67) to pass through. Composing with none
of it supplied leaves those placeholders blank rather than erroring.

Any module can register a further field provider —
`App\Services\Documents\DocumentFieldRegistry::register()` — without OPES360
Documents needing to know that module exists.

### Templates

A business's own templates, alongside the built-in catalogue — which has no
endpoint of its own; it is code, reachable through the Papers gallery.

`GET /api/v1/document-templates` — every custom template, published or not.
`GET /api/v1/document-templates/{template}` — one, with its body.
`GET /api/v1/document-templates/{template}/versions` — its history.

`POST /api/v1/document-templates` — `key` (lowercase/digits/underscore,
refused with 422 if it collides with a built-in template's key), `name`,
`body`, and optionally `summary`, `icon`, `accent`, `binding`, `fields`.
Starts unpublished. Requires `papers.manage`.

`PUT /api/v1/document-templates/{template}` — same fields, `key` excluded
(never changes once set). Editing wording or fields writes a new version;
renaming or re-summarising does not.

`POST .../publish` and `.../unpublish` — puts a template in the gallery
alongside the built-in ones, or takes it out. Neither writes a version;
publishing is not content.

`DELETE /api/v1/document-templates/{template}` — removes the template.
Documents already composed from it are untouched; a document's text is
copied in at compose time, never read from the template afterwards.

### Numbering

`GET /api/v1/library/numbering-schemes` — every configured scheme.

`POST /api/v1/library/numbering-schemes` — `kind` (null for the catch-all),
`prefix` (uppercase/digits/hyphens only), `requires_number` (default true —
set false for a kind that should carry no number at all). Saving a second
scheme for a kind already configured replaces it rather than duplicating it.
Requires `papers.manage`.

`DELETE /api/v1/library/numbering-schemes/{scheme}` — removes it; the kind
falls back to whatever the catch-all says, or the shared `DOC-2026-000001`
series if nothing else applies.

A company that never calls this endpoint sees no change at all — every kind
keeps numbering on the existing shared series. This has no effect on
invoices, quotations, proformas, or receipts; each of those keeps its own
existing numbering untouched.

### Retention and legal hold

`GET /api/v1/library/{document}/retention` — the applicable retention date
(from the business's own policy for that kind, or its catch-all; `null` if
neither exists), whether a legal hold is active, and whether the document is
disposable right now.

`POST /api/v1/library/{document}/legal-hold` (`reason`) and
`DELETE .../legal-hold` — place or lift a hold. Requires `papers.manage`,
which is the confidentiality-and-administration ability, not `papers.share`
or `update` — a hold is a governance act, not a filing or content one.

`DELETE /api/v1/library/{document}/dispose` — permanent removal, not the
ordinary soft delete `DELETE /api/v1/library/{document}` performs. Refused
with 422 if a legal hold is active or the retention period has not passed.
There is no override parameter: a parameter that bypasses a hold is a hold
that does not actually hold.

Retention policies themselves (`business_document_retention_policies`) have
no API yet — set today through the service layer only, noted honestly rather
than left for someone to discover missing.

### External sharing

`GET /api/v1/library/{document}/shares` — every link created for the
document, live or not, with a view count. Never returns the password.

`POST /api/v1/library/{document}/shares` — `expires_at` (must be in the
future), `password` (min 4 characters), `allow_download` (default true).
Requires `papers.share`.

`POST /api/v1/library/shares/{share}/revoke` — turns a link off immediately.
The row stays; only its liveness changes.

The link itself — `/share/{share_token}` — is public and unauthenticated,
resolved cross-tenant the same way `/v/{token}` and `/sign/{token}` already
are. A password-protected link asks for the password first and does not log
an access until it is entered correctly. `allow_download` hides the
print/save button; it is a courtesy setting, not an enforced restriction —
anyone who can see a page in a browser can still screenshot it.

### Signatures

`GET /api/v1/library/{document}/signatures` — status (`not_requested`,
`pending`, `partially_signed`, `fully_signed`, `declined`) and every signer.

`POST /api/v1/library/{document}/signatures` — `signers` (name + email each)
and an optional `mode` (`sequential` or `parallel`, default `parallel`).
Requires `papers.share`, the same ability sending a document anywhere outside
the business already requires. Refused with 422 if a round is already in
progress.

Signing itself is **not** a token-API action — the signer is not a user of
the business, and has no token. They act at `/sign/{signing_token}`, an
unauthenticated public page reached by an emailed link, mirroring how
`/v/{token}` already works for public verification: no session, no current
company, the token itself naming both. On full completion the document gets a
`VerificationToken` the same way `DocumentIssuer` already mints one on issue —
the existing QR verification infrastructure, not a second one.

### Comments

`GET /api/v1/library/{document}/comments` — top-level comments, each with its
replies nested underneath.

`POST /api/v1/library/{document}/comments` — `body`, optional `parent_id` for
a reply, optional `mentioned_user_ids`. Mentions are ids, not `@name` parsed
out of the text — a parser guesses who was meant and is wrong the day two
colleagues share a name.

`POST /api/v1/library/comments/{comment}/resolve` and `/reopen` — the
comment's author, the document's owner, or a document administrator.

`DELETE /api/v1/library/comments/{comment}` — the comment's author, or a
document administrator.

## 23. What is not here yet

Every module through Library, including its versions, has an API. **Projects
does not yet** — it shipped 2026-08-16 with a screen and no API, and that is a
gap to close, not a choice; noted here rather than let the claim below
overstate what exists.

Everything else absent below is absent by choice, and each section above says
why in its own place:

- Creating and editing events, and building forms — write-once things that
  nothing else produces.
- Awarding or adjusting loyalty points by hand. Earning already happens as a
  side effect of a payment.
- Refunding a VIP membership fee as its own act. It is an ordinary payment
  refund, because that is what it is.
- Deleting an issued ticket. Voiding keeps the serial, and a vanished serial
  makes an honest buyer indistinguishable from a forged one.
- Posting to the ledger by hand. Every entry is the consequence of a business
  event that already has its own endpoint.
- Running, approving or paying a payroll month. That is the owner's signature,
  not a token's.

`PATCH /api/v1/deals/{id}` accepts a `stage` and applies the same closure rules
as `/move`, so neither can leave `closed_at` disagreeing with the stage. Prefer
`/move` anyway: it is the action a board performs, and `lost_reason` belongs
with it.
