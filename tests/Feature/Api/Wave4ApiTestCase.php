<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\EstateController;
use App\Http\Controllers\Api\InsuranceClaimController;
use App\Http\Controllers\Api\InsurancePolicyController;
use App\Http\Controllers\Api\ManufacturingController;
use App\Http\Controllers\Api\SalesOrderController;
use App\Http\Controllers\Api\ServiceTicketController;
use App\Http\Controllers\Api\ShipmentController;
use App\Http\Controllers\Api\WorkflowRuleController;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One company with every Wave-4 module switched on, and the Wave-4 routes
 * registered exactly as the handoff block writes them — the same pattern
 * RecruitmentTestCase set: routes/api.php is the orchestrator's file, so the
 * feature proves itself over HTTP with its own copy of the block
 * (docs/handoff/wave4a.md), names omitted so the paste cannot collide.
 */
abstract class Wave4ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        // The six newer modules all ship off; the module under test has to be
        // switched on for the business under test.
        $this->company->forceFill(['modules' => [
            'service' => true,
            'orders' => true,
            'insurance' => true,
            'logistics' => true,
            'estate' => true,
            'manufacturing' => true,
        ]])->save();
        Modules::flush();

        app(CurrentCompany::class)->set($this->company);

        $this->registerWave4Routes();

        Sanctum::actingAs($this->owner, ['*']);
    }

    protected function registerWave4Routes(): void
    {
        Route::prefix('api/v1')->middleware(['api', 'auth:sanctum'])->group(function (): void {
            Route::middleware('ability:read')->group(function (): void {
                Route::get('service/tickets', [ServiceTicketController::class, 'index']);
                Route::get('service/tickets/{ticket}', [ServiceTicketController::class, 'show']);

                Route::get('orders', [SalesOrderController::class, 'index']);
                Route::get('orders/{order}', [SalesOrderController::class, 'show']);

                Route::get('insurance/policies', [InsurancePolicyController::class, 'index']);
                Route::get('insurance/policies/{policy}', [InsurancePolicyController::class, 'show']);
                Route::get('insurance/claims', [InsuranceClaimController::class, 'index']);
                Route::get('insurance/claims/{claim}', [InsuranceClaimController::class, 'show']);

                Route::get('logistics/shipments', [ShipmentController::class, 'index']);
                Route::get('logistics/shipments/{shipment}', [ShipmentController::class, 'show']);
                Route::get('logistics/shipments/{shipment}/events', [ShipmentController::class, 'events']);

                Route::get('estate/properties', [EstateController::class, 'properties']);
                Route::get('estate/properties/{property}', [EstateController::class, 'showProperty']);
                Route::get('estate/tenancies', [EstateController::class, 'tenancies']);
                Route::get('estate/tenancies/{tenancy}', [EstateController::class, 'showTenancy']);

                Route::get('manufacturing/boms', [ManufacturingController::class, 'boms']);
                Route::get('manufacturing/boms/{bom}', [ManufacturingController::class, 'showBom']);
                Route::get('manufacturing/orders', [ManufacturingController::class, 'orders']);
                Route::get('manufacturing/orders/{order}', [ManufacturingController::class, 'showOrder']);

                Route::get('workflows', [WorkflowRuleController::class, 'index']);
                Route::get('workflows/{workflow}', [WorkflowRuleController::class, 'show']);
            });

            Route::middleware('ability:write')->group(function (): void {
                Route::post('service/tickets', [ServiceTicketController::class, 'store']);
                Route::post('service/tickets/{ticket}/respond', [ServiceTicketController::class, 'respond']);
                Route::post('service/tickets/{ticket}/resolve', [ServiceTicketController::class, 'resolve']);

                Route::post('orders', [SalesOrderController::class, 'store']);
                Route::post('orders/{order}/confirm', [SalesOrderController::class, 'confirm']);
                Route::post('orders/{order}/deliver', [SalesOrderController::class, 'deliver']);

                Route::post('insurance/policies', [InsurancePolicyController::class, 'store']);
                Route::post('insurance/policies/{policy}/claims', [InsuranceClaimController::class, 'store']);

                Route::post('logistics/shipments', [ShipmentController::class, 'store']);

                Route::post('manufacturing/orders', [ManufacturingController::class, 'storeOrder']);
                Route::post('manufacturing/orders/{order}/complete', [ManufacturingController::class, 'complete']);

                Route::post('workflows', [WorkflowRuleController::class, 'store']);
                Route::match(['put', 'patch'], 'workflows/{workflow}', [WorkflowRuleController::class, 'update']);
            });
        });
    }

    protected function makeContact(string $name = 'Boulangerie Nkolbisson'): Contact
    {
        return Contact::create(['type' => 'customer', 'name' => $name]);
    }

    /** A tracked product with stock already on the shelf. */
    protected function makeStockedItem(string $name, float $onHand, float $price = 1000, float $cost = 500): Item
    {
        $item = Item::create([
            'name' => $name,
            'type' => 'product',
            'price' => $price,
            'cost' => $cost,
            'track_stock' => true,
        ]);

        if ($onHand > 0) {
            StockMovement::create([
                'item_id' => $item->id,
                'quantity' => $onHand,
                'unit_cost' => $cost,
                'reason' => 'adjustment',
                'occurred_at' => now(),
            ]);
        }

        return $item;
    }
}
