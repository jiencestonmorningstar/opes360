<?php

namespace Tests\Feature\Estate;

use App\Livewire\Estate\Index as EstateIndex;
use App\Livewire\Estate\Show as EstateShow;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\Role;
use App\Models\Tenancy;
use App\Models\User;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EstateScreenTest extends TestCase
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
            'slug' => 'immo-'.Str::lower(Str::random(4)),
            'name' => 'Immo Akwa Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        $this->company->forceFill(['modules' => ['estate' => true, 'service' => true]])->save();
        Modules::flush();

        app(CurrentCompany::class)->set($this->company);

        foreach (['estate.view', 'estate.manage', 'estate.end-tenancy'] as $ability) {
            Gate::define($ability, fn (User $user) => true);
        }

        ChartOfAccounts::seed($this->company);

        // The routes belong to the integrator (routes/* untouched); the
        // blades link by name, so the names are registered test-locally.
        Route::get('/estate', EstateIndex::class)->name('estate');
        Route::get('/estate/{property}', EstateShow::class)->name('estate.show');

        $this->actingAs($this->owner);
    }

    public function test_the_index_renders_the_board_and_adds_a_property(): void
    {
        Livewire::test(EstateIndex::class)
            ->assertStatus(200)
            ->call('startAdding')
            ->set('name', 'Immeuble Bonapriso')
            ->set('kind', 'mixed')
            ->call('add')
            ->assertHasNoErrors();

        $this->assertNotNull(Property::query()->where('name', 'Immeuble Bonapriso')->first());
    }

    public function test_the_show_screen_lets_a_unit_and_the_whole_chain_lands(): void
    {
        $property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Immeuble Bali',
            'kind' => 'residential',
        ]);
        $tenant = Contact::create(['name' => 'Jean Mbarga', 'balance' => 0]);

        Livewire::test(EstateShow::class, ['property' => $property])
            ->call('startAddingUnit')
            ->set('unitLabel', 'Studio 1')
            ->set('unitTargetRent', '100000')
            ->call('addUnit')
            ->assertHasNoErrors();

        $unit = $property->units()->first();
        $this->assertSame('vacant', $unit->status);

        Livewire::test(EstateShow::class, ['property' => $property])
            ->call('startLetting', $unit->id)
            ->set('tenantId', $tenant->id)
            ->set('rent', '100000')
            ->set('depositAmount', '200000')
            ->call('let')
            ->assertHasNoErrors();

        $tenancy = Tenancy::query()->first();
        $this->assertSame('active', $tenancy->status);
        $this->assertSame('occupied', $unit->refresh()->status);
        $this->assertSame('lease', $tenancy->lease->type);
        $this->assertNotNull($tenancy->deposit_entry_id);
    }

    public function test_the_screen_surfaces_the_service_refusal_instead_of_crashing(): void
    {
        $property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Immeuble Deido',
            'kind' => 'residential',
        ]);
        $unit = PropertyUnit::create([
            'company_id' => $this->company->id,
            'property_id' => $property->id,
            'label' => 'Shop 2',
            'status' => 'unavailable',
        ]);
        $tenant = Contact::create(['name' => 'Awa Sob', 'balance' => 0]);

        Livewire::test(EstateShow::class, ['property' => $property])
            ->call('startLetting', $unit->id)
            ->set('tenantId', $tenant->id)
            ->set('rent', '50000')
            ->call('let')
            ->assertHasErrors('letting');

        $this->assertSame(0, Tenancy::query()->count());
    }
}
