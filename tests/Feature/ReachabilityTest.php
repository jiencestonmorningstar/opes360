<?php

namespace Tests\Feature;

use App\Livewire\Customers\Show as CustomerScreen;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Can a person actually get there?
 *
 * The failure this guards against is invisible to every other kind of test. A
 * route that answers 200 passes anything you can write about it while being
 * unreachable by a human, because the test knows the URL and the human does
 * not. This codebase has produced that four times: credit notes were an enum
 * case with no button, invitations were a column with no form, the secretariat
 * demo account was seeded in full and never offered, and editing a customer had
 * a route, a form and a policy — and no link, anywhere, since the CRM shipped.
 *
 * `php artisan opes:unreachable` finds the shape of it across the whole app.
 * This pins the ones that have already bitten.
 */
class ReachabilityTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        $this->customer = Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    /**
     * Somebody has to be able to fix a misspelled name or a changed phone
     * number, and for the whole life of the CRM they could not.
     */
    public function test_a_customer_can_be_edited_from_their_own_page(): void
    {
        Livewire::actingAs($this->owner)
            ->test(CustomerScreen::class, ['contact' => $this->customer])
            ->assertSee(route('customers.edit', $this->customer), false);
    }

    /** And the link is not offered to somebody who would be refused it. */
    public function test_the_edit_link_is_not_offered_to_someone_who_cannot_use_it(): void
    {
        $readOnly = User::factory()->create();
        $this->joinCompany($this->company, $readOnly, Role::READ_ONLY);

        Livewire::actingAs($readOnly)
            ->test(CustomerScreen::class, ['contact' => $this->customer])
            ->assertDontSee(route('customers.edit', $this->customer), false);
    }

    public function test_the_edit_page_itself_loads_and_is_guarded(): void
    {
        $this->actingAs($this->owner)
            ->get(route('customers.edit', $this->customer))
            ->assertOk()
            ->assertSee('Un Client');

        $readOnly = User::factory()->create();
        $this->joinCompany($this->company, $readOnly, Role::READ_ONLY);
        $readOnly->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($readOnly)
            ->get(route('customers.edit', $this->customer))
            ->assertForbidden();
    }

    /**
     * Every demo account the seeders build must be offered on the login page.
     *
     * The secretariat was seeded with a client book, issued cards, earnings,
     * four employees and a paid payroll run, and the only way in was knowing
     * the address by heart.
     */
    public function test_every_seeded_demo_account_is_reachable_from_the_login_page(): void
    {
        $offered = collect(config('opes.demo.accounts'))->pluck('email');
        $page = $this->get('/login')->assertOk()->getContent();

        foreach (['john@opesware.com', 'sales@opesware.com', 'secretariat@opesware.com'] as $email) {
            $this->assertTrue($offered->contains($email), "{$email} is seeded but not offered.");
            $this->assertStringContainsString($email, $page);
        }
    }
}
