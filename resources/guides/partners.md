# The partner programme

A partner — a **secretariat**, in this product's vocabulary — is a print shop
or business centre that produces stationery for the small businesses around it
and signs those businesses up to the platform. It is a paid commercial
arrangement, not a feature toggle: the partner is charged for what they print
and paid a share of what their referrals spend.

"Partner" and "secretariat" mean the same thing here. The programme is a
property of the *account*, not of a person's role — an Owner of an ordinary
business cannot reach these screens at all, and a secretariat cannot switch
the programme off, because it is how the account pays its way.

## The client book

**Clients** is the partner's list of the businesses it prints for and hopes
to enrol. These are deliberately not Contacts: a contact is somebody you sell
to, with a balance and a document history; a partner client is a prospect with
an invite link and a conversion state instead.

Each client carries an invite token, and the partner also has a code of its
own. Either one works in a registration link, because both end up printed on
things and people type whichever is in front of them. When a business registers
through a partner's link, it is attributed to that partner — **once, and
permanently**. A business already attributed is left alone, so a second
partner sending the same person a link later cannot take over a referral
somebody else earned. A partner referring itself is refused: that would be a
discount, not a referral.

The book shows which clients converted into real accounts and which are still
prospects, and how many cards have been billed against each.

## Issuing cards

From a client's page the partner picks a design — there are ninety-eight,
grouped by sector so a mechanic does not scroll past ninety cards to find one
that looks like a garage — plus four letterhead designs, then confirms.

Confirming is a separate, deliberate step because **it is the step that
charges**. Each card or letterhead issued costs the partner **500 XAF**
(`config/opes.php`, `partners.card_fee`). The fee is copied onto the issuance
record at the moment of issue, so a later price change can never rewrite what
a past month cost.

## Commission

When a business the partner enrolled pays for its subscription, the partner is
credited **10%** of that payment (`partners.commission_rate`). Two things about
how that happens are worth knowing:

- It is credited by the subscription biller **after the money actually
  settles** — never against a pending payment or an unpaid invoice. A payment
  that fails earns nothing.
- It is credited **exactly once per payment**. A provider webhook and a manual
  status check racing each other cannot pay the partner twice; the ledger
  refuses a second credit for the same payment.

The commission is recurring: it applies to every successful subscription
payment the referred business makes, for as long as it keeps paying. The rate
and base amount are stamped onto each commission row, so changing the rate
later changes future earnings only.

## The ledger and the balance

The **Earnings** screen is a ledger with three sides:

- **Earned** — commission from referred businesses' settled payments.
- **Fees** — the card and letterhead charges.
- **Withdrawn** — payouts already made, plus any request still in flight.

The balance is always `earned − fees − withdrawn`, recomputed from the ledger
rows every time rather than kept as a stored total — a stored balance and a
transaction list that disagree is a support conversation nobody wins. The
balance can legitimately be negative: a partner who has printed a hundred
cards and enrolled nobody owes the platform, not the other way round.

## Payouts

A partner can request a payout once the balance reaches **10,000 XAF**
(`partners.payout_minimum`) — below that it is not worth a mobile-money fee.
The request names a destination: an MTN or Orange mobile-money number, or a
bank account.

The amount paid is **the whole balance as the ledger says it right now**, not
a figure from the form — a balance shown a few minutes ago is not a promise.
A requested payout counts against the balance immediately, so the same money
cannot be requested twice while the first request waits to be settled. All
amounts are whole XAF; the currency has no minor unit, so there is no rounding
to argue about.

## Who can do what

| Ability | What it allows |
|---|---|
| `partners.view` | Open the client book and the earnings screen |
| `partners.manage` | Add and edit clients |
| `partners.issue` | Issue a card or letterhead — the act that incurs the fee |
| `partners.withdraw` | Request a payout |

Every one of these is additionally conditioned on the account being a
secretariat. On an ordinary business they are denied outright, whatever role
the person holds, which is why the Clients and Earnings entries never appear
in a plain business's navigation.

`issue` and `withdraw` are separate from `manage` because they cost and move
money respectively. An assistant maintaining the client book should not, by
that fact alone, be able to run up fees or empty the balance.

## Over the API

The whole programme is available at `/api/v1/partners/*` — clients, earnings,
commissions and payouts to read; adding clients under the `write` token
ability; requesting a payout under `money`, and idempotent, because a retry
that created a second request would ask to be paid twice for the same
earnings. See [API tokens and webhooks](/guides/api-tokens-and-webhooks).

## Related

- [API tokens and webhooks](/guides/api-tokens-and-webhooks) — running the
  client book and payouts from your own system.
- [VIP memberships](/guides/vip) — the other paid programme, on the business
  side rather than the platform side.
