# VIP memberships

A VIP membership is a paid tier that discounts a member's invoices for the
term they bought. The customer pays once to join — Gold for a year, say — and
everything they buy while the membership is live is discounted at the tier's
rate.

The module is **off by default**: most businesses will never run a membership
programme, and an empty screen with nothing to do on it helps nobody. Switch
it on under Settings → Modules. It requires the customer book, because a
membership belongs to somebody.

## Tiers

A tier is the offer: a name, a joining price, a term in months, the discount
percentage the member gets, and the perks you promise in words. Set them up
under **VIP → Tiers**.

Editing a tier changes what **future** sales get, never what a member was
already sold. Every membership carries its own copy of the terms it was sold
under — the tier name, the discount, the price paid — so raising Gold's price
next year cannot rewrite what today's member holds.

For the same reason a tier is **withdrawn rather than deleted**. Deleting the
row would not change what anybody was sold, but it would erase the answer to
"what was Gold, back then". A withdrawn tier can no longer be sold; existing
members are unaffected; and it can be put back on sale later.

## Selling a membership

From **VIP → Members**, choose the customer and the tier. Selling raises a
**real invoice** for the fee through the ordinary sales path, so the money
shows up in revenue, in the customer's balance and in the books like anything
else the business sells — there is no side channel for membership income.

Two rules about the term:

- **Renewing early extends, not restarts.** If the customer already has a live
  membership, the new term starts the day after the current one ends. A member
  who renews in month ten keeps the two months they already paid for.
- **Month-end dates behave as a subscription is understood to.** A one-month
  term sold on 31 January ends on the last day of February rather than
  spilling into March; the anniversary does not drift.

While a membership is live, the member's discount is applied to their
invoices automatically. If a contact somehow holds more than one live
membership, the **highest** discount wins, not the most recent.

## The member card

Every membership sold gets a card: a number in the form `VIP-XXXXXXXX`, with a
prefix so a number read down a phone line is recognisable as what it is, and a
QR code that resolves to a verification token. The person on the door can scan
a printed card and check it against the business without the member needing an
account — showing a card at the door is the case the card exists for.

The printable designs are shared with the rest of the product's card
stationery.

## Lapsing and cancelling

A membership ends in one of two ways:

- **It lapses.** When the end date passes, the discount stops — immediately
  and by itself, because the discount check refuses any membership past its
  `ends_on`, whether or not the nightly tidy-up has run. The overnight sweep
  then marks each lapsed membership expired, one by one rather than in bulk,
  precisely so the business can be told *which* member lapsed — a lapsed
  member is somebody worth winning back.
- **It is cancelled.** Cancelling stops the benefit before the end date and
  keeps the record, with the reason and the date. It does not refund the fee;
  if money should go back, that is a credit note against the invoice, decided
  by a person.

Each of these moments — sold, cancelled, expired — fires a webhook
(`vip.membership.sold`, `vip.membership.cancelled`, `vip.membership.expired`)
for any system that wants to keep pace, such as a door app or a mailing list.

## Who can do what

| Ability | What it allows |
|---|---|
| `vip.view` | See the tiers and the member list |
| `vip.sell` | Sign a customer up — which raises the invoice |
| `vip.manage` | Design the programme: create, edit and withdraw tiers; cancel memberships |

`sell` is separate from `manage` on purpose: a receptionist may sign somebody
up for Gold without being able to change what Gold costs or what it gives.

## Over the API

Tiers can be read and managed, and memberships read and cancelled, under the
`write` token ability. **Selling** a membership sits under the `money` ability
and is idempotent, because it raises an invoice — a retry after a dropped
connection must not sell and charge twice. See
[API tokens and webhooks](/guides/api-tokens-and-webhooks).

## Related

- [Selling and getting paid](/guides/sales-and-invoicing) — the invoice the
  membership fee arrives on, and the discount on later sales.
- [Customers](/guides/customers) — the contact the membership belongs to.
- [API tokens and webhooks](/guides/api-tokens-and-webhooks) — selling and
  checking memberships from another system.
