# API tokens and webhooks

Everything the screens do to business records, the JSON API at `/api/v1` can
do too — same tenant boundary, same permission checks, same rules. This guide
gets you from nothing to an authenticated call and a webhook subscription;
the full endpoint reference lives in `docs/API.md`, and a machine-readable
OpenAPI 3.1 description is served at `/openapi.json`, generated from the
router itself by `php artisan opes:export-openapi` — generated rather than
hand-written, because a spec maintained by hand drifts, and a spec that lies
is worse than none.

## Creating a token

Issuing a token is the one call that cannot require a token:

```http
POST /api/v1/tokens
Content-Type: application/json

{
  "email": "you@example.com",
  "password": "…",
  "device_name": "reporting-dashboard",
  "abilities": ["read"]
}
```

The response contains the token **once**; store it, it is not shown again.
The endpoint is throttled exactly like the login form — same email+IP key,
same limits — so it cannot be used to grind passwords at a rate the login
page would have refused. An account with two-factor authentication enabled is
refused here: a second factor cannot be completed over a single request, and
quietly skipping it would make the API a way around it.

Send the token on every other request:

```http
Authorization: Bearer 3|Xk9…
Accept: application/json
```

`GET /api/v1/user` tells you who the token is and what it may do — useful for
hiding what a client cannot reach rather than discovering it through 403s.
`DELETE /api/v1/tokens/current` revokes it. Both work whatever abilities the
token was minted with: a token must always be able to say who it is and to
revoke itself.

## Token abilities

Abilities are a **ceiling, never a grant**. A token carrying `write` still
cannot issue an invoice if its user lacks `sales.issue`; the user's
permissions and the module switches are checked exactly as on the web, and
the ability narrows the result. A token can never do more than the user it
belongs to could do while signed in.

| Ability | What it allows |
|---|---|
| `read` | Read anything the user can read |
| `write` | Create and change records the user could |
| `money` | Move value: record payments, refund, settle expenses, sell tickets and VIP memberships, request partner payouts |
| `people` | Read the staff file and payroll figures |

They are deliberately not implied by each other — a dashboard token asking
for `read` does not thereby get payroll, and a token that can add a customer
cannot take a payment. Omit `abilities` entirely and the token gets `*`,
everything its user can do — the right shape for a script the owner wrote for
themselves. Asking only for ability names that do not exist fails with a
validation error rather than quietly upgrading to full access.

## Calling the API

Routes are versioned (`/api/v1/…`) so a future v2 can disagree without
breaking you. Reads sit under `read`; ordinary writes under `write`; and
anything that moves money — payments, refunds, ticket sales, VIP sales,
partner payouts — under `money`, where every request also accepts an
`Idempotency-Key` header. Send the same key on a retry after a dropped
connection and the API does the work once and replays the first answer,
instead of charging twice. Document issue, void, convert and credit-note are
idempotent too, because a burned invoice number is also not a thing to repeat.

## Webhooks

A webhook tells your server the moment something happens, instead of your
polling for it. Register an endpoint with `POST /api/v1/webhooks`, naming a
URL and the events you want; the signing secret is shown **once** at
registration and never appears in a read response.

The event catalogue is deliberately short — only real business moments, each
fired from the one service that owns it, never from a model save:

| Event | Fires when |
|---|---|
| `document.issued` | An invoice, quotation or other document was issued |
| `document.voided` | An issued document was cancelled |
| `payment.recorded` | A customer payment was recorded and its receipt issued |
| `expense.recorded` | A supplier bill or an expense was entered |
| `deal.won` | A pipeline deal was moved to won |
| `contact.created` | A customer or supplier was added |
| `vip.membership.sold` | A customer bought a VIP membership |
| `vip.membership.cancelled` | A VIP membership was cancelled early |
| `vip.membership.expired` | A VIP membership reached its end date |

Names are part of the wire contract and are never renamed.

Deliveries are queued after the business transaction commits, so you are
never told about something that did not happen, and a slow or down endpoint
can never fail a payment being taken at a counter. Each delivery carries
`Opes-Event`, a unique `Opes-Delivery` id, and a signature:

```
Opes-Signature: t=<unix timestamp>,v1=<hex digest>
```

The digest is an HMAC-SHA256, keyed on your secret, over the timestamp, a
dot, and the exact raw bytes of the body. Verify it before trusting the
payload, and compare digests with a constant-time function; `docs/API.md`
includes a ready-made PHP verifier.

`GET /api/v1/webhooks/deliveries` is the log of what was sent to you and how
your server answered — the first place to look when "did that sale reach my
system" comes up — and `POST /api/v1/webhooks/deliveries/{id}/redeliver`
sends one again.

One user-permission note: registering or changing webhooks requires
`webhooks.manage`, which belongs to the Owner and the Administrator and
nobody else — subscribing to `payment.recorded` is a standing export of the
business's revenue, and that is a decision, not a task.

## Related

- [Bringing your data in](/guides/imports) — the import endpoints, and the
  screen that does the same job.
- [VIP memberships](/guides/vip) and
  [The partner programme](/guides/partners) — the programmes whose money
  moves sit under the `money` ability.
