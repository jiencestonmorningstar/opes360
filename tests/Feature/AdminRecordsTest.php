<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\Admin\AdminResources;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The platform admin's record browser.
 *
 * The point of the registry is that nothing gets left behind when a module
 * ships, so the first test walks every registered resource rather than naming
 * a few — a new entry with a broken column closure fails here rather than in
 * front of whoever is answering the support ticket.
 */
class AdminRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected PlatformAdmin $admin;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = PlatformAdmin::create([
            'name' => 'Platform Admin',
            'email' => 'admin@opes360.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        $this->owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        app(CurrentCompany::class)->set($this->company);
    }

    public function test_every_registered_resource_renders(): void
    {
        foreach (AdminResources::keys() as $key) {
            $this->actingAs($this->admin, 'admin')
                ->get(route('admin.records', ['resource' => $key]))
                ->assertOk();
        }
    }

    public function test_every_registered_resource_exports(): void
    {
        foreach (AdminResources::keys() as $key) {
            $this->actingAs($this->admin, 'admin')
                ->get(route('admin.records.export', ['resource' => $key]))
                ->assertOk()
                ->assertDownload();
        }
    }

    public function test_an_unknown_resource_is_not_found(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.records', ['resource' => 'nonsense']))
            ->assertNotFound();
    }

    public function test_the_browser_needs_an_admin_session(): void
    {
        $this->get(route('admin.records', ['resource' => 'documents']))
            ->assertRedirect(route('admin.login'));

        // A signed-in business user is not a platform admin either.
        $this->actingAs($this->owner)
            ->get(route('admin.records', ['resource' => 'documents']))
            ->assertRedirect(route('admin.login'));
    }

    public function test_records_are_visible_across_the_tenant_scope(): void
    {
        // The admin guard belongs to no company, so the tenant scope would
        // otherwise fail closed and show an empty table for every business.
        Contact::create(['name' => 'Ndongo Ltd']);
        Item::create(['name' => 'Consulting', 'price' => 50000, 'type' => 'service']);

        app(CurrentCompany::class)->set(null);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.records', ['resource' => 'customers']))
            ->assertOk()
            ->assertSee('Ndongo Ltd');

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.records', ['resource' => 'products']))
            ->assertOk()
            ->assertSee('Consulting');
    }

    public function test_the_list_can_be_narrowed_to_one_business(): void
    {
        Contact::create(['name' => 'Acme Customer']);

        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'beta',
            'name' => 'Beta Ltd',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
        ]);

        app(CurrentCompany::class)->as($other, fn () => Contact::create(['name' => 'Beta Customer']));
        app(CurrentCompany::class)->set(null);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.records', ['resource' => 'customers', 'company' => 'acme']))
            ->assertOk()
            ->assertSee('Acme Customer')
            ->assertDontSee('Beta Customer');
    }

    public function test_search_narrows_the_list(): void
    {
        Contact::create(['name' => 'Findable Person']);
        Contact::create(['name' => 'Someone Else']);

        app(CurrentCompany::class)->set(null);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.records', ['resource' => 'customers', 'q' => 'Findable']))
            ->assertOk()
            ->assertSee('Findable Person')
            ->assertDontSee('Someone Else');
    }

    public function test_subscription_payments_are_visible_to_the_platform(): void
    {
        // The money the platform itself is owed — previously invisible in the
        // admin panel, which is the one place it needs to be reconcilable.
        SubscriptionPayment::create([
            'company_id' => $this->company->id,
            'plan' => 'growth',
            'billing_cycle' => 'monthly',
            'amount' => 9000,
            'currency' => 'XAF',
            'provider' => 'mtn_momo',
            'phone' => '670416238',
            'external_id' => (string) Str::uuid(),
            'status' => 'successful',
        ]);

        app(CurrentCompany::class)->set(null);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.records', ['resource' => 'subscription-payments']))
            ->assertOk()
            ->assertSee('MTN MoMo')
            ->assertSee('670416238')
            ->assertSee('Successful');
    }

    public function test_soft_deleted_records_remain_visible(): void
    {
        $contact = Contact::create(['name' => 'Deleted Customer']);
        $contact->delete();

        app(CurrentCompany::class)->set(null);

        // A support question about a vanished record is answered by seeing it.
        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.records', ['resource' => 'customers']))
            ->assertOk()
            ->assertSee('Deleted Customer');
    }

    public function test_the_browser_writes_nothing(): void
    {
        $contact = Contact::create(['name' => 'Untouched']);
        $before = $contact->updated_at;

        app(CurrentCompany::class)->set(null);

        $this->actingAs($this->admin, 'admin')
            ->get(route('admin.records', ['resource' => 'customers']))
            ->assertOk();

        $this->assertEquals($before, $contact->fresh()->updated_at);
    }
}
