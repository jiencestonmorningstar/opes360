# Wave 4a handoff — API routes for the newer modules

Controllers, resources and tests are committed. `routes/api.php` is yours:
paste the two blocks below. The tests register the same routes themselves
(`tests/Feature/Api/Wave4ApiTestCase.php`, the RecruitmentTestCase pattern),
so they pass before and after the paste — but the endpoints only exist in
production once this lands.

## 1. Imports (top of routes/api.php)

```php
use App\Http\Controllers\Api\EstateController;
use App\Http\Controllers\Api\InsuranceClaimController;
use App\Http\Controllers\Api\InsurancePolicyController;
use App\Http\Controllers\Api\ManufacturingController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\ServiceTicketController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\WorkflowRuleController;
```

## 2. Reads — inside the existing `ability:read` group

```php
            // ── Wave 4: the newer modules ────────────────────────────────
            // Every route relies on the ability gate, which already answers
            // the module switch (Gate::before) as well as the permission.
            Route::get('service/tickets', [ServiceTicketController::class, 'index'])->name('api.v1.service.tickets.index');
            Route::get('service/tickets/{ticket}', [ServiceTicketController::class, 'show'])->name('api.v1.service.tickets.show');

            Route::get('orders', [SalesOrderController::class, 'index'])->name('api.v1.orders.index');
            Route::get('orders/{order}', [SalesOrderController::class, 'show'])->name('api.v1.orders.show');

            Route::get('insurance/policies', [InsurancePolicyController::class, 'index'])->name('api.v1.insurance.policies.index');
            Route::get('insurance/policies/{policy}', [InsurancePolicyController::class, 'show'])->name('api.v1.insurance.policies.show');
            Route::get('insurance/claims', [InsuranceClaimController::class, 'index'])->name('api.v1.insurance.claims.index');
            Route::get('insurance/claims/{claim}', [InsuranceClaimController::class, 'show'])->name('api.v1.insurance.claims.show');

            Route::get('logistics/shipments', [ShipmentController::class, 'index'])->name('api.v1.logistics.shipments.index');
            Route::get('logistics/shipments/{shipment}', [ShipmentController::class, 'show'])->name('api.v1.logistics.shipments.show');
            Route::get('logistics/shipments/{shipment}/events', [ShipmentController::class, 'events'])->name('api.v1.logistics.shipments.events');

            Route::get('estate/properties', [EstateController::class, 'properties'])->name('api.v1.estate.properties.index');
            Route::get('estate/properties/{property}', [EstateController::class, 'showProperty'])->name('api.v1.estate.properties.show');
            Route::get('estate/tenancies', [EstateController::class, 'tenancies'])->name('api.v1.estate.tenancies.index');
            Route::get('estate/tenancies/{tenancy}', [EstateController::class, 'showTenancy'])->name('api.v1.estate.tenancies.show');

            Route::get('manufacturing/boms', [ManufacturingController::class, 'boms'])->name('api.v1.manufacturing.boms.index');
            Route::get('manufacturing/boms/{bom}', [ManufacturingController::class, 'showBom'])->name('api.v1.manufacturing.boms.show');
            Route::get('manufacturing/orders', [ManufacturingController::class, 'orders'])->name('api.v1.manufacturing.orders.index');
            Route::get('manufacturing/orders/{order}', [ManufacturingController::class, 'showOrder'])->name('api.v1.manufacturing.orders.show');

            Route::get('workflows', [WorkflowRuleController::class, 'index'])->name('api.v1.workflows.index');
            Route::get('workflows/{workflow}', [WorkflowRuleController::class, 'show'])->name('api.v1.workflows.show');
```

## 3. Writes — inside the existing `ability:write` group

```php
            // ── Wave 4: the newer modules ────────────────────────────────
            Route::post('service/tickets', [ServiceTicketController::class, 'store'])->name('api.v1.service.tickets.store');
            Route::post('service/tickets/{ticket}/respond', [ServiceTicketController::class, 'respond'])->name('api.v1.service.tickets.respond');
            Route::post('service/tickets/{ticket}/resolve', [ServiceTicketController::class, 'resolve'])->name('api.v1.service.tickets.resolve');

            // Confirm commits stock and deliver moves it, so both are
            // idempotent: a retry after a dropped connection must not reserve
            // or ship the same goods twice.
            Route::post('orders', [SalesOrderController::class, 'store'])->name('api.v1.orders.store');
            Route::post('orders/{order}/confirm', [SalesOrderController::class, 'confirm'])
                ->middleware('idempotent')->name('api.v1.orders.confirm');
            Route::post('orders/{order}/deliver', [SalesOrderController::class, 'deliver'])
                ->middleware('idempotent')->name('api.v1.orders.deliver');

            Route::post('insurance/policies', [InsurancePolicyController::class, 'store'])->name('api.v1.insurance.policies.store');
            Route::post('insurance/policies/{policy}/claims', [InsuranceClaimController::class, 'store'])->name('api.v1.insurance.claims.store');

            Route::post('logistics/shipments', [ShipmentController::class, 'store'])->name('api.v1.logistics.shipments.store');

            Route::post('manufacturing/orders', [ManufacturingController::class, 'storeOrder'])->name('api.v1.manufacturing.orders.store');
            // Completion writes real stock movements, so a retry must not
            // consume the components twice.
            Route::post('manufacturing/orders/{order}/complete', [ManufacturingController::class, 'complete'])
                ->middleware('idempotent')->name('api.v1.manufacturing.orders.complete');

            Route::post('workflows', [WorkflowRuleController::class, 'store'])->name('api.v1.workflows.store');
            Route::match(['put', 'patch'], 'workflows/{workflow}', [WorkflowRuleController::class, 'update'])->name('api.v1.workflows.update');
```

## Notes for the orchestrator

- **Abilities**: service.view/create/update/complete, orders.view/manage/
  confirm/deliver, insurance.view/manage, logistics.view/manage, estate.view,
  manufacturing.view/manage/complete, workflows.view/manage — all already in
  `Permissions::CATALOGUE`. No new slugs.
- **Module gate**: nothing extra needed. `Gate::before` in AuthServiceProvider
  maps each ability to its module and denies when switched off; all six
  vertical modules default off, so a business sees 403 until it opts in.
- **Scopes**: reads under `read`, writes under `write`. Nothing here moves
  money — an order's invoice still goes out through the existing documents
  endpoints — so none of it sits under `money`.
- The docs are updated (`docs/API.md` §24–§30).
