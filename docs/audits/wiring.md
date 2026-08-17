# Wiring audit — Opes360

Read-only audit, 2026-08-17. Sources: `php artisan route:list --json` (364 routes),
grep over `app/`, `resources/`, `config/`, `routes/`. No fixes applied.

---

## 1. Orphan screens (components/controllers with no route)

### Livewire components never routed and never embedded

| Class | View | Status |
|---|---|---|
| `App\Livewire\Audit\History` | `resources/views/livewire/audit/history.blade.php` | **Orphan.** Not in any route file, never rendered via `@livewire`/`<livewire:>`. Class + blade fully built, zero references outside itself. |

Not orphans (checked and cleared):
- `App\Livewire\Dashboard` — invoked from the `/` closure in `routes/web.php:222` (`app(Dashboard::class)()` when authenticated).
- `App\Livewire\Notifications\Bell`, `App\Livewire\Search\Palette` — embedded via `@livewire('notifications.bell')` / `@livewire('search.palette')` in the layout.
- All other 99 of 103 Livewire classes are directly routed.

### Controllers with no route

- `App\Http\Controllers\Api\ApiController` — abstract base class, fine.
- `App\Http\Controllers\Concerns\AbortsForSuspendedCompany` — trait, fine.

Every concrete controller is routed. **Net: 1 real orphan (Audit\History).**

---

## 2. `route()` calls referencing non-existent route names

Scanned every `route('name')` literal in `app/`, `resources/`, `config/` against the 364
registered route names.

- **0 broken references.** The only non-matching hit is `Notification::route('mail', ...)`
  in `MarketingController.php:97` and `Services/Dunning.php:175` — the notification
  channel API, not a URL route. False positive.

---

## 3. Routes missing permission middleware their siblings carry

Method: grouped authenticated (non-admin) web routes by first URI segment; flagged
routes without an `Authorize:` middleware inside groups where siblings have one.

| Route | Siblings carry | Mitigation found |
|---|---|---|
| `POST customers/{contact}/loyalty-card/issue` (`loyalty.issue`) | `can:view,contact` on GET siblings | Controller calls `$this->authorize('update', $contact)` + `loyalty.manage` — covered, but the only writes in the `customers/` group with no route-level gate. |
| `POST customers/{contact}/loyalty/redeem` (`loyalty.redeem`) | same | Controller authorizes `view` + `loyalty.redeem`. |
| `POST customers/{contact}/loyalty/adjust` (`loyalty.adjust`) | same | Controller authorizes `update` + `loyalty.manage`. |
| `GET hr/reviews` (`hr.reviews`) | `hr/*` siblings carry `Authorize:attendance.view` etc. | Deliberate per `config/opes.php:109` comment ("everybody has their own review to sign"). |
| `GET settings`, `GET settings/api-tokens`, `GET settings/notifications` | `settings/billing`, `settings/webhooks`, `settings/notification-rules` carry abilities | Per-user pages; likely intentional, but `settings/api-tokens` mints API tokens with **no ability gate at route or (verify) component level** while `settings/webhooks` requires one — inconsistent trust level. |

Admin routes: consistent (`auth:admin` everywhere; destructive ops add
`EnsurePlatformAdminRole`). No gaps found there.

---

## 4. Nav / quick actions (`config/opes.php`) vs screens

### Nav or quick-action entries pointing at missing routes
- **None.** All 45 navigation `route` values and all 15 quick-action `route` values
  resolve to registered route names.

### Routed screens with NO nav entry and NO inbound link anywhere (unreachable except by typing the URL)

| URI | Route name | Component |
|---|---|---|
| `assets/fleet` | `assets.fleet` | `App\Livewire\Fleet\Vehicles` — fleet screen exists, zero links to it anywhere in views or components. |
| `audit/governance` | `audit.governance` | `App\Livewire\Audit\Governance` — routed but nothing links to it (the audit index does not). |
| `library/analytics` | `papers.analytics` | `App\Livewire\Papers\Analytics` |
| `payables/runs` | `payables.runs` | `App\Livewire\Payables\Runs` — payment-run screen; `payables.reconcile` is well linked (12 refs), runs is not. |
| `reports/collections` | `reports.collections` | `App\Livewire\Reports\Collections` |
| `reports/statement` | `reports.statement` | `App\Livewire\Reports\Statement` |
| `settings/notification-rules` | `settings.notification-rules` | `App\Livewire\Settings\NotificationRules` |
| `settings/notifications` | `settings.notifications` | notification prefs page — no link from settings index or the bell. |

### Routed screens absent from nav but linked from a parent screen (fine)
`accounting.declarations`, `artisans`, `assets.movements`, `business.branding`,
`departments`, `business.reviews`, `deals.create`, `payables.reconcile`,
`reports.aging`, `service.sla`, `settings.api-tokens`, `settings.billing`,
`settings.webhooks`, `vip.tiers`, `stationery`, `logo`, `businesses`, `register`.

---

## 5. API coverage vs newer modules (`routes/api.php`)

API v1 exposes: contacts, documents, payments, expenses, items, deals, employees,
payroll runs/payslips, events/tickets, forms, library (deep), loyalty, VIP,
partners, accounting statements, approvals (decide on pending approvals), imports,
branding, tokens, webhooks.

**Zero API surface** for:

| Module | Notes |
|---|---|
| **Insurance vertical** | No `insurance/*` routes. Policies/claims unreachable via API. |
| **Sales orders & delivery vertical** | No `orders`, `delivery-notes`. |
| **Logistics vertical** | No `shipments`, `trips`. (Public tracking exists on web only.) |
| **Estate vertical** | No `properties`, `tenancies`. |
| **Manufacturing** | No `boms`, `production-orders`. |
| **Service desk** | No `service/tickets`, jobs, SLA. A customer-ticketing module with no API is a notable gap — ticket ingestion is the classic API use case. |
| **Workflows** | `approvals` endpoints let a user decide pending approvals, but workflow *rules* (CRUD) have no API. Partial. |
| Also uncovered (older) | contracts, projects, fixed assets, banking, compliance/risks, payables, procurement, recruitment, leads (deals only), attendance/reviews. |

---

## 6. `config/modules.php` models lacking policies

Registered/auto-discoverable policies exist for 14 of the 56 model classes referenced
in `config/modules.php` (`app/Policies/` has 20 files; Lead & CrmActivity rely on
Laravel auto-discovery, unregistered in `AuthServiceProvider::$policies`).

**42 module models with no policy class:**

AssetLocation, AssetMaintenance, AssetTransfer, AttendanceRecord, BankAccount,
BillOfMaterial, ComplianceObligation, Contract, DeliveryNote, Employee, Expense,
FixedAsset, FuelLog, InsuranceClaim, InsurancePolicy, JobApplication, JobOffer,
PartnerClient, PaymentRun, PayrollRun, Payslip, PerformanceReview, ProductionOrder,
Property, PurchaseRequisition, Rfq, Risk, SalesOrder, ServiceJob, ServiceSlaPolicy,
ServiceTicket, Shipment, StockLocation, Stocktake, SupplierQuotation,
SupplierStatement, Tenancy, TripManifest, Vacancy, VehicleDetail, VehicleTrip, VipTier

Consequence: for these models `can:view,{model}` route middleware is impossible, and
the modules.php header's promise that "model-backed policy checks (`can:view,document`)
are covered too" holds only for the 14 covered models. Detail screens for the other
modules rely solely on page-level abilities + company scoping.

### Bonus defect found while auditing modules.php

`config/modules.php:320`:

```php
'models' => [BillOfMaterial::class, ProductionOrder::class],
```

**Neither class is imported** (no `use App\Models\BillOfMaterial;` /
`use App\Models\ProductionOrder;` in the file header). In a namespace-less config file
`::class` resolves to the *root* namespace, so the manufacturing module's model map
stores `"BillOfMaterial"` and `"ProductionOrder"` instead of
`"App\Models\BillOfMaterial"` / `"App\Models\ProductionOrder"`. Any code that matches
a model's FQCN against this map will never match the manufacturing models — meaning
disabling the manufacturing module does **not** close policy/model-level access to
BOM and production-order records the way it does for every other module.

---

## Counts

| Category | Count |
|---|---|
| 1. Orphan Livewire screens (built, unrouted, unembedded) | 1 |
| 1. Orphan controllers | 0 |
| 2. Broken `route()` name references | 0 |
| 3. Routes missing sibling permission middleware (real, after mitigations) | 7 flagged / 4 defensible |
| 4. Nav entries pointing at missing routes | 0 |
| 4. Routed screens unreachable from any link or nav | 8 |
| 5. Modules with zero API coverage | 6 newer (+10 older) |
| 6. Module models without policies | 42 of 56 |
| Bonus: unimported `::class` refs in modules.php | 2 |
