<?php

namespace Tests\Feature\Logistics;

use App\Http\Controllers\PrintController;
use App\Http\Controllers\ShipmentTrackingController;
use App\Livewire\Logistics\Index;
use App\Livewire\Logistics\Show;
use App\Models\Company;
use App\Models\Contact;
use App\Models\FixedAsset;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\TripManifest;
use App\Models\User;
use App\Models\VehicleDetail;
use App\Services\Logistics\Dispatch;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One company, one truck on the asset register, two contacts — everything a
 * shipment needs to exist.
 */
abstract class LogisticsTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected FixedAsset $truck;

    protected Contact $sender;

    protected Contact $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'haulage-'.Str::lower(Str::random(6)),
            'name' => 'Haulage Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        /*
         * The catalogue entry the orchestrator will add to config/modules.php
         * — declared here because that file is the orchestrator's, not this
         * feature's. Logistics ships OFF: most businesses on this product do
         * not run trucks. Off means every logistics.* gate denies, so the
         * module under test is switched on for the business under test.
         */
        config(['modules.logistics' => [
            'label' => 'Logistics',
            'description' => 'Shipments, trip manifests and public tracking.',
            'icon' => 'truck',
            'default' => false,
            'requires' => ['customers'],
            'groups' => ['logistics'],
            'models' => [Shipment::class, TripManifest::class],
        ]]);

        $this->company->forceFill(['modules' => ['logistics' => true]])->save();
        Modules::flush();

        app(CurrentCompany::class)->set($this->company);

        /*
         * The logistics abilities are not in App\Support\Permissions yet —
         * that file belongs to the orchestrator. These definitions are exactly
         * what AuthServiceProvider's loop will produce once the slugs land in
         * the catalogue: view / manage / dispatch, with dispatch split out
         * because it is the act that commits the vehicle and every promise
         * aboard. Pending cataloguing, the owner passes on all three.
         */
        foreach (['logistics.view', 'logistics.manage', 'logistics.dispatch'] as $ability) {
            Gate::define($ability, function (User $user) {
                $company = app(CurrentCompany::class)->get();

                return $company !== null && $company->users()->whereKey($user->id)->exists();
            });
        }

        /*
         * The routes the orchestrator will register — declared here so the
         * feature is proven end-to-end over HTTP. The same lines appear in
         * the handoff document, throttle included.
         */
        Route::middleware('web')->group(function () {
            Route::get('/track/{token}', [ShipmentTrackingController::class, 'show'])
                ->middleware('throttle:60,1')->name('shipment.track');
        });

        Route::middleware(['web', 'auth'])->group(function () {
            Route::get('/logistics', Index::class)->name('logistics');
            Route::get('/logistics/{shipment}', Show::class)->name('logistics.show');

            // The print pair — the same lines the handoff hands the
            // orchestrator, hardening doc included.
            Route::get('/logistics/{shipment}/waybill/print', [PrintController::class, 'waybill'])
                ->name('logistics.waybill.print');
            Route::get('/logistics/manifests/{manifest}/print', [PrintController::class, 'manifest'])
                ->name('logistics.manifest.print');
        });

        // Names assigned after the collection was built need the lookup
        // rebuilt, or route('logistics.waybill.print') cannot find them.
        Route::getRoutes()->refreshNameLookups();

        $this->truck = FixedAsset::create([
            'company_id' => $this->company->id,
            'name' => 'Mercedes Actros',
            'category' => 'vehicles',
            'status' => 'active',
            'cost' => 45_000_000,
            'acquired_on' => now()->subYear()->toDateString(),
            'method' => 'straight_line',
            'useful_life_months' => 60,
        ]);

        VehicleDetail::create([
            'company_id' => $this->company->id,
            'fixed_asset_id' => $this->truck->id,
            'registration' => 'LT-234-AB',
            'make' => 'Mercedes',
            'model' => 'Actros',
        ]);

        $this->sender = Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Ets Fotso & Fils',
            'email' => 'fotso@example.test',
        ]);

        $this->receiver = Contact::create([
            'company_id' => $this->company->id,
            'name' => 'Quincaillerie du Wouri',
            'email' => 'wouri@example.test',
        ]);
    }

    protected function dispatcher(): Dispatch
    {
        return app(Dispatch::class);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function shipment(array $overrides = []): Shipment
    {
        return $this->dispatcher()->book(array_merge([
            'sender_id' => $this->sender->id,
            'receiver_id' => $this->receiver->id,
            'cargo_description' => '40 sacks of cement',
            'weight_kg' => 2000,
            'from_location' => 'Douala',
            'to_location' => 'Bafoussam',
            'freight_amount' => 250_000,
        ], $overrides), $this->owner);
    }

    protected function manifest(): TripManifest
    {
        return $this->dispatcher()->openManifest(
            $this->truck->fresh(),
            $this->owner,
            now()->addDay()->toDateString(),
            $this->owner,
        );
    }
}
