# Launch polish — guides, demo scenes and marketing for the four verticals

Final integration pass over the insurance, orders, logistics and estate
verticals: their in-product guides, their demo scenes, and their marketing
presence. Everything below is grounded in the shipped services — nothing
described here is aspirational.

## Guides

Four entries added to `app/Support/Guides.php` under a new editorial group,
**Industries**, with bodies in `resources/guides/`:

| Slug | Title | The decisions it carries |
| --- | --- | --- |
| `insurance` | Broking insurance | The alarm is cover lapsing before renewal is agreed; there is no approve button on a claim — settlement goes through the shared workflow and settles at the amount the approvers saw. |
| `sales-orders` | Customer orders and delivery | Confirm reserves what exists and names what does not (the visible backorder); deliver is the moment stock moves, in one transaction with the note; the invoice bills what actually went. |
| `logistics` | Running a transport business | POD is an e-signature through the one signature flow; the tracking token shows one shipment's reference, route, parties and history — never the manifest, vehicle, driver or values. |
| `property-management` | Letting a property | A lease is a real Contract; the deposit is posted to 165 as a liability, never a memo column; retaining any of it needs a stated reason and becomes income (7078) at settlement only. |

Each guide ends with a Related section linking sibling guides. The
catalogue↔file pairing test (`--filter="Guide"`, 14 tests) passes.

## Demo scenes (`database/seeders/DemoModulesSeeder.php`)

The seeder now enables `insurance`, `orders`, `logistics`, `estate` alongside
the modules it already switched on, then seeds one honest scene per module —
**through the real services**, each guarded by an `exists()` check so a rerun
adds nothing (verified: two consecutive runs, identical counts).

- **Insurance** — motor cover lapsing in ten days on auto-renew (notice date
  twenty days gone: the red list), premium invoiced, commission earned and
  uninvoiced, and an assessed claim whose 380 000 settlement sits with the
  owner for approval. A `Claim settlements` workflow (owner approves) is
  created if none exists, mirroring the DefaultWorkflows shape, because
  `submitSettlement` correctly refuses without a path.
- **Orders** — Ciment 50kg received 6, ordered 10, confirmed: the line shows
  4 backordered and the item has a reorder level so the board can answer
  whether replenishment covers it. A second order (rebar) is confirmed,
  delivered (printable DLV note with verification token) and invoiced.
- **Logistics** — a truck (FixedAsset + VehicleDetail), three bookings: one
  waiting, a dispatched manifest with two aboard, one of which is delivered
  with `withPod: true` — the POD paper is drafted and sent for the
  receiver's signature through `DocumentSignatureRequests` and left honestly
  *awaiting signature*: completing it would mean the seeder impersonating
  the receiver's signing token, a state the product cannot really reach.
  Its freight invoice is drafted. The in-transit shipment's tracking link is
  printed via `$this->command?->line`.
- **Estate** — Immeuble Bonanjo with three units: one occupied through
  `Tenancies::start()` (lease on the contract register, rent on the
  recurring generator, 300 000 caution posted to 165), one vacant against
  its asking rent, and one tenancy in arrears via a backdated issued rent
  invoice 45 days past due — written to the model with a comment, the
  seeder's one sanctioned exception, because no service issues an invoice
  into the past. The board's arrears figure is Aging's own row over it.

## Marketing

- `about.blade.php` — four sector cards added to the "Who it is for" grid,
  each with one line of shipped-feature evidence (policy watch + approval
  settlement; visible backorders + QR delivery notes; manifests + e-signed
  POD + private tracking; contract-lease + deposits as liabilities).
- `features.blade.php` — a seventh group, **The industries**, with the four
  modules described from `config/modules.php` truths; the "built for" list in
  the honesty section now names distributors, brokers, transporters and
  property managers; the stale comment grounding the section in "supply
  chain is not built" was corrected. The manufacturer/multi-entity "not yet"
  line stands — still true.
- `home.blade.php` — Insurance and Real Estate added to the industries
  strip (Distribution and Logistics were already there). The module-count
  stat reads `count(config('modules'))` and needed nothing.

## Verification

- `php artisan test --filter="Guide|Marketing|Landing|Modules"` — 62 passed
  (907 assertions), no assertions weakened.
- `php artisan db:seed --class=DemoModulesSeeder --force` — clean twice
  against the dev SQLite database; second run added no rows.
- Pint clean on `Guides.php` and `DemoModulesSeeder.php`.
