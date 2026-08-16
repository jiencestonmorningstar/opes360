# Logistics hardening — handoff

Second pass over the vertical in `docs/handoff/logistics.md`: gaps scanned,
then built. Everything below rides the existing paths — printing through
PrintController + `App\Support\Pdf`, tenancy from the token on public pages,
invoices through the ordinary Document tables.

## Gap list (scan result → what was built)

| # | Gap found | Built |
|---|---|---|
| 1 | No waybill — the shipment had a tracking link but no paper for the driver to hand over | `PrintController::waybill` + `resources/views/print/waybill.blade.php`: sender, receiver, cargo, declared value, tracking QR (`QrCodes`), signature line. `?format=pdf` downloads a real file through `App\Support\Pdf`. Watermarked CANCELLED / RETURNED per the Watermarks doctrine. Deliberately **no freight amount** — the paper travels through third hands; money lives on the invoice (test pins this). |
| 2 | No rate cards — freight typed from memory on every booking | `freight_rates` table (migration `2026_09_16_000302`), `App\Models\FreightRate`, `App\Services\Logistics\RateCards` (`quote` = per-kg × weight floored at minimum; `put` upserts per route; case-insensitive route match). The booking form proposes the quote on blur and **never overwrites a figure the clerk typed** — the card proposes, the person decides. |
| 3 | No failed-delivery path — a van turned away had nowhere to say so | `Dispatch::failDelivery` (reason REQUIRED, written into the event history the tracking page shows), status `exception` — red strip at the top of the board and a red chip on the shipment. Ways out: `retryDelivery` (back in transit) or `returnToSender` (settled end state, like delivered/cancelled). `close()` now closes over `exception`/`returned` cargo — the van came home; the argument about the absent receiver must not strand the odometer readings. |
| 4 | Events carried no tracking URL for receiver notification | `logistics.shipment.booked` / `.delivered` / `.failed` all carry `tracking_url` (and `.failed` carries `reason`) in context. Events only — the notification system decides delivery. |
| 5 | No driver loading sheet | `PrintController::manifest` + `print/manifest.blade.php`: cargo aboard, per-line and total weight, stops in order, depot + driver signature lines. DRAFT watermark while the manifest is still open. **No tracking links and no money** — internal paper (test pins the token never prints). |
| 6 | Shipment + TripManifest missing from global search | Added to `App\Search\GlobalSearch::sources()` + `GROUP_LABELS` (ability `logistics.view`, module-switch enforced by the existing fence). Shipment found by reference or party name; the tracking token is never indexed (test pins it). Manifest points at the board (`logistics`) — it has no detail page. |
| 7 | Logistics events absent from the catalogue | `DomainEvents::CATALOGUE['logistics']`: `logistics.shipment.booked`, `.delivered`, `.failed`, `.invoiced`, `logistics.manifest.dispatched` — every name the service emits, now selectable by automation/notification rules (test pins each). |
| 8 | Audit registration | See below — orchestrator's list. |
| 9 | Fleet sync unproven | `tests/Feature/Logistics/ManifestFleetSyncTest.php`: closing with readings writes ONE `VehicleTrip` linked from the manifest, and `VehicleUsage::odometer()` — the number distance-based servicing schedules from — reads the manifest's closing reading. |

## Routes the orchestrator must add (routes/web.php, inside the company group)

```php
Route::get('/logistics/{shipment}/waybill/print', [\App\Http\Controllers\PrintController::class, 'waybill'])
    ->middleware('can:logistics.view')->name('logistics.waybill.print');
Route::get('/logistics/manifests/{manifest}/print', [\App\Http\Controllers\PrintController::class, 'manifest'])
    ->middleware('can:logistics.view')->name('logistics.manifest.print');
```

The screens guard the links with `Route::has(...)`, so nothing breaks before
these land — the print buttons simply do not render. The tests register the
same lines locally (LogisticsTestCase), idempotent per run.

## Audit registration (§8 — orchestrator: AppServiceProvider's AuditObserver list)

Models that should join the `AuditObserver` loop, under "commitments made":

- `App\Models\Shipment` — somebody's cargo and a public promise; status turns
  are already event rows, but edits to parties/amounts are not.
- `App\Models\TripManifest` — commits a vehicle and a driver for a day.
- `App\Models\FreightRate` — a price list; a quietly edited rate is a
  quietly edited invoice-to-be.

(`ShipmentEvent` and the pivot need no observer — append-only by the service.)

## New statuses

`Shipment::STATUSES` grew `exception` (loud, not settled) and `returned`
(settled). `isSettled()` = delivered / cancelled / returned. The tracking
page and event history label both through the existing `statusLabel()` path —
no view changes needed there.

## Demo scene (DemoModulesSeeder::logistics — extended)

- A rate card Douala → Yaoundé (120/kg, 60 000 floor); the first booking is
  priced by `RateCards::quote` (216 000), not typed.
- A fourth shipment aboard the dispatched manifest fails delivery
  ("Destinataire absent au dépôt") — the red exception card on the board.
- Idempotency unchanged: the whole scene is guarded by
  `Shipment::query()->exists()`, so seeding twice adds nothing.

## Also touched (why)

- `app/Console/Commands/SearchReindex.php` — it assumed every search source
  soft-deletes (`getQualifiedDeletedAtColumn()`); Shipment/TripManifest do
  not delete at all. The trashed-row exclusion now applies only to models
  that have the column. GlobalSearchTest's reindex test covers it.
- `resources/guides/logistics.md` — sections added for the waybill, rate
  cards, the loading sheet, failed deliveries, and receiver notification.

## Verify

`php artisan test --filter="Logistics|Shipment|Fleet|Search|Pdf"` — 123 pass
(56 in `tests/Feature/Logistics`, incl. new `WaybillPrintTest`,
`RateCardTest`, `DeliveryExceptionTest`, `ManifestFleetSyncTest`,
`LogisticsSearchTest`). Pint clean.
