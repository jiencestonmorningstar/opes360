# Logistics (transporters) — handoff

Vertical per `docs/superpowers/plans/2026-08-16-industry-verticals.md` §3.
A thin domain layer: the truck is a FixedAsset, the parties are Contacts,
the freight invoice is an ordinary Document, the POD signs through the one
signature flow, tracking follows the share-token pattern.

## What shipped

- **Migration** `database/migrations/2026_09_15_000301_create_logistics_tables.php`
  — `shipments`, `shipment_events` (the status history the public page
  renders), `trip_manifests` (vehicle = `fixed_asset_id`, driver = BIGINT
  user), `trip_manifest_shipments` pivot. The one-open-manifest rule is
  enforced in the service inside a transaction (an "open" predicate is not
  expressible in a plain unique index); `tms_manifest_shipment_unique` is the
  floor for same-manifest races. Long pivot index names are spelled out to
  stay under 64 chars.
- **Models** `Shipment`, `TripManifest`, `ShipmentEvent`,
  `TripManifestShipment` (pivot model, only so ULID keys mint on attach).
  Additive relations: `FixedAsset::manifests()`, `Contact::shipmentsSent()`
  / `shipmentsReceived()`.
- **Service** `App\Services\Logistics\Dispatch` — `book`, `openManifest`,
  `load`, `dispatch`, `deliver` (optional POD), `cancel`, `close`,
  `draftInvoice`. Every turn of a shipment's status writes a ShipmentEvent.
- **Screens** `App\Livewire\Logistics\Index` (dispatch board: waiting /
  loading / on the road) and `Show` (history, POD, invoice link) with views
  under `resources/views/livewire/logistics/`.
- **Public** `App\Http\Controllers\ShipmentTrackingController` +
  `resources/views/public/track.blade.php`.
- **Tests** `tests/Feature/Logistics/` — 25 tests. Run:
  `php artisan test --filter="Logistics|Shipment|Fleet|Signature"` (77 pass).

## Decisions argued

- **Loading does NOT write a VehicleTrip.** Loading is warehouse work — the
  van has not moved, and a `VehicleTrip` is two odometer readings that do not
  exist until it returns. Instead `Dispatch::close()` accepts optional
  start/end odometer readings and writes ONE `VehicleTrip`
  (purpose `Manifest MAN-…`), linking it on `trip_manifests.vehicle_trip_id`
  — so the truck's mileage/servicing arithmetic sees dispatch journeys like
  any other, with no second mileage store.
- **POD** — `deliver(..., withPod: true)` drafts a `BusinessDocument`
  ("Proof of delivery — SHP-…"), requests the receiver's signature through
  `DocumentSignatureRequests` (the existing flow: signing token, sequence
  rules, verification token on completion), and stamps
  `shipments.pod_document_id`. No second signature machine.
- **Freight invoice** — `draftInvoice` builds an ordinary draft sales
  `Document` (one line, `Vat::forCompany`, billed to the sender) and stores
  only `shipments.document_id`. Draft, not issued; it issues through
  `DocumentIssuer` like every other invoice. Same shape as
  `ServiceBilling::draft` — a fourth line-assembly; the pending
  `InvoiceDrafter` extraction noted there now has one more caller waiting.
- **Tracking page privacy** — renders reference, route, the shipment's own
  two party names, and the event history. Never the manifest, vehicle,
  driver, freight/declared value, or any other cargo. Tests pin this,
  including cross-tenant.

## Routes (orchestrator: routes/web.php — not touched by this task)

```php
// Public — outside auth, with the same throttle family as /triage and /v:
Route::get('/track/{token}', [\App\Http\Controllers\ShipmentTrackingController::class, 'show'])
    ->middleware('throttle:60,1')->name('shipment.track');

// Authed, inside the company group:
Route::get('/logistics', \App\Livewire\Logistics\Index::class)
    ->middleware('can:logistics.view')->name('logistics');
Route::get('/logistics/{shipment}', \App\Livewire\Logistics\Show::class)
    ->middleware('can:logistics.view')->name('logistics.show');
// (No Shipment policy shipped; the modules `models` map above still takes the
// detail page down with the switch once catalogued. Add a policy if per-row
// checks are ever wanted.)
```

(The tests register exactly these lines themselves; delete nothing when
cataloguing — the test-local registration is idempotent per test run.)

## Module entry (config/modules.php)

```php
'logistics' => [
    'label' => 'Logistics',
    'description' => 'Shipments, trip manifests, proof of delivery and public tracking.',
    'icon' => 'truck',
    // Off: most businesses on this product do not run trucks.
    'default' => false,
    // Sender and receiver are Contacts, so the customer book must exist.
    // `assets` is recommended, not required, in config: a manifest needs a
    // vehicle off the register, but the module refuses at the service level
    // ("no vehicle record") rather than dying at the switch.
    'requires' => ['customers'],
    'groups' => ['logistics'],
    'models' => [\App\Models\Shipment::class, \App\Models\TripManifest::class],
],
```

The tracking controller already goes dark (404) when the module is off,
`Modules::exists()`-guarded so it keeps working pre-cataloguing.

## Permission group (Permissions.php — orchestrator)

Group `logistics`, three abilities, per the house doctrine of splitting the
money-committing act out of manage:

- `logistics.view` — the board and shipment screens.
- `logistics.manage` — book, open manifests, load, deliver (incl. POD),
  cancel, draft the freight invoice.
- `logistics.dispatch` — **the committing act.** Dispatching commits the
  vehicle for the day and turns every booking aboard into a promise on a
  public page; delivery and invoicing merely record what then happened.
  Seed to Manager *minus* `logistics.dispatch` (Owner/Admin only), matching
  settle-claim / confirm-order / close-tenancy in the sibling verticals.

The test case defines these three gates locally (membership-only, pending
cataloguing) and states so in a comment — swap to `hasPermissionIn` seeding
once the slugs land.

## Nav

One entry, `active === 'logistics'`, label "Dispatch", icon `truck`, behind
`can:logistics.view`, pointing at `route('logistics')` — beside Service desk
in the operations cluster.

## Guide sketch (resources/guides/ — orchestrator)

"Run your transport business": book a shipment (sender/receiver from the
customer book, the tracking link to give the customer) → open a manifest
(a truck from your asset register + driver) → load → dispatch (everything
aboard goes in transit on the public page) → deliver with a signed POD →
close the manifest with the odometer readings (writes the fleet log) →
draft the freight invoice (an ordinary invoice, dunning and receipts apply).

## Demo scene (DemoModulesSeeder — orchestrator)

An in-transit manifest: one truck (asset + VehicleDetail), a driver, two
shipments aboard `in_transit` (events: booked→loaded→in_transit), one more
waiting `booked`, one `delivered` yesterday with a fully-signed POD paper and
an issued freight invoice. Gives the board all three columns and the tracking
link something honest to show.
