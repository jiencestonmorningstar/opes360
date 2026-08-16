<?php

namespace Tests\Feature\Orders;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\Role;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Orders\Fulfilment;
use App\Services\Stock\StockLedger;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The shared floor under the hardening tests: a company with the orders
 * module on, the gates defined (feature, not wiring — the same stance as
 * FulfilmentTest), the route names the blades link to, and a customer
 * with products on the shelf.
 */
abstract class OrdersTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $customer;

    protected Item $cement;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'depot-'.Str::lower(Str::random(4)),
            'name' => 'Depot Central Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
            // The module ships off; switched on for the business under test.
            'modules' => ['orders' => true, 'products' => true, 'sales' => true],
        ]);
        Modules::flush();

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        // The real routes exist in routes/web.php by now; only the gates are
        // defined here, so these tests exercise the feature, not the wiring.
        foreach (['orders.view', 'orders.manage', 'orders.confirm', 'orders.deliver'] as $ability) {
            Gate::define($ability, fn (User $user) => true);
        }

        $this->customer = Contact::create([
            'company_id' => $this->company->id,
            'type' => 'customer',
            'name' => 'Chantier Mbarga',
        ]);

        $this->cement = $this->product('Ciment 50kg', 'CIM', price: 6500);
    }

    protected function product(string $name, string $sku, float $price): Item
    {
        return Item::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'sku' => $sku,
            'type' => 'product',
            'price' => $price,
            'track_stock' => true,
            'is_active' => true,
        ]);
    }

    protected function stockUp(Item $item, float $quantity, float $unitCost): void
    {
        app(StockLedger::class)->receive($this->company, $item, $quantity, $unitCost, actor: $this->owner);
    }

    protected function draftOrder(array $lines): SalesOrder
    {
        return app(Fulfilment::class)->create([
            'contact_id' => $this->customer->id,
            'lines' => $lines,
        ], $this->owner);
    }
}
