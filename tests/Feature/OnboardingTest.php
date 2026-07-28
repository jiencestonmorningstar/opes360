<?php

namespace Tests\Feature;

use App\Livewire\Business\Edit as BusinessEdit;
use App\Livewire\Onboarding\Register;
use App\Livewire\Products\Form as ProductForm;
use App\Models\Company;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\VerificationToken;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Registration assigns the Owner role, which must exist first.
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_registration_creates_a_user_company_and_identity_token(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Ada Obi')
            ->set('email', 'ada@example.com')
            ->set('password', 'secret-password')
            ->set('passwordConfirmation', 'secret-password')
            ->call('continueToBusiness')
            ->assertSet('step', 2)
            ->set('businessName', 'Ada Fabrics')
            ->set('industry', 'Fashion')
            ->set('currency', 'NGN')
            ->call('finish')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $company = Company::where('slug', 'ada-fabrics')->firstOrFail();

        $this->assertSame($company->id, $user->current_company_id);
        $this->assertSame('NGN', $company->currency);
        $this->assertTrue($user->belongsToCompany($company));
        $this->assertSame('Owner', $user->roleIn($company)->name);
        $this->assertAuthenticatedAs($user);

        // The business QR works from the first minute.
        $this->assertDatabaseHas('verification_tokens', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
        ]);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        Livewire::test(Register::class)
            ->set('name', 'Someone')
            ->set('email', 'taken@example.com')
            ->set('password', 'secret-password')
            ->set('passwordConfirmation', 'secret-password')
            ->call('continueToBusiness')
            ->assertHasErrors(['email'])
            ->assertSet('step', 1);
    }

    public function test_registration_requires_matching_passwords(): void
    {
        Livewire::test(Register::class)
            ->set('name', 'Someone')
            ->set('email', 'new@example.com')
            ->set('password', 'secret-password')
            ->set('passwordConfirmation', 'different-password')
            ->call('continueToBusiness')
            ->assertHasErrors(['password']);
    }

    public function test_slugs_do_not_collide_between_businesses(): void
    {
        foreach ([['a@example.com', 'Ada Fabrics'], ['b@example.com', 'Ada Fabrics']] as [$email, $name]) {
            Livewire::test(Register::class)
                ->set('name', 'Owner')
                ->set('email', $email)
                ->set('password', 'secret-password')
                ->set('passwordConfirmation', 'secret-password')
                ->call('continueToBusiness')
                ->set('businessName', $name)
                ->call('finish')
                ->assertHasNoErrors();

            auth()->logout();
        }

        $this->assertSame(2, Company::where('name', 'Ada Fabrics')->count());
        $this->assertSame(2, Company::query()->distinct()->count('slug'));
    }

    /** Sets up a signed-in owner for the pages that need one. */
    protected function signInOwner(): User
    {
        $user = User::factory()->create();

        $company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $user->id,
            'currency' => 'USD',
        ]);

        $company->users()->attach($user->id, [
            'role_id' => Role::where('slug', 'owner')->value('id'),
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $user->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        return $user;
    }

    public function test_the_business_page_saves_identity_and_encrypts_the_tax_id(): void
    {
        $user = $this->signInOwner();

        Livewire::actingAs($user)
            ->test(BusinessEdit::class)
            ->set('form.name', 'Acme Trading Ltd')
            ->set('form.motto', 'We deliver')
            ->set('form.industry', 'Retail')
            ->set('form.tax_id', 'TIN-99887766')
            ->set('form.phone1', '+234 800 111 2222')
            ->set('form.currency', 'NGN')
            ->call('save')
            ->assertHasNoErrors();

        $company = Company::firstOrFail();

        $this->assertSame('Acme Trading Ltd', $company->name);
        $this->assertSame(['+234 800 111 2222'], $company->phones);
        $this->assertSame('TIN-99887766', $company->tax_id);

        // Encrypted at rest: the ciphertext in the row is not the plain value,
        // and the blind index carries lookups instead.
        $raw = \DB::table('companies')->where('id', $company->id)->first();
        $this->assertNotSame('TIN-99887766', $raw->tax_id);
        $this->assertNotNull($raw->tax_id_index);
    }

    public function test_the_business_page_mints_an_identity_token_on_first_visit(): void
    {
        $user = $this->signInOwner();

        $this->assertSame(0, VerificationToken::count());

        Livewire::actingAs($user)->test(BusinessEdit::class)->assertOk();

        $this->assertSame(1, VerificationToken::count());
    }

    public function test_creating_a_tracked_product_records_opening_stock_as_a_movement(): void
    {
        $user = $this->signInOwner();

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['type' => 'product'])
            ->set('form.name', 'Cement Bag')
            ->set('form.sku', 'CEM-001')
            ->set('form.price', '12.50')
            ->set('form.track_stock', true)
            ->set('form.reorder_level', '10')
            ->set('openingStock', '40')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('products', ['type' => 'product']));

        $item = Item::firstOrFail();

        $this->assertSame('Cement Bag', $item->name);
        $this->assertTrue($item->track_stock);
        // Stock is the movement ledger, never a column.
        $this->assertSame(40.0, $item->stockOnHand());
        $this->assertFalse($item->isLowStock());
        $this->assertDatabaseHas('stock_movements', ['item_id' => $item->id, 'reason' => 'opening']);
    }

    public function test_a_service_never_tracks_stock(): void
    {
        $user = $this->signInOwner();

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['type' => 'service'])
            ->set('form.name', 'Consulting hour')
            ->set('form.price', '75')
            ->set('form.track_stock', true)   // ignored for services
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Item::firstOrFail()->track_stock);
    }

    public function test_editing_a_product_updates_it_without_a_new_movement(): void
    {
        $user = $this->signInOwner();

        $item = Item::create([
            'type' => 'product',
            'name' => 'Old name',
            'price' => 10,
            'track_stock' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ProductForm::class, ['item' => $item])
            ->assertSet('form.name', 'Old name')
            ->set('form.name', 'New name')
            ->set('form.price', '15')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New name', $item->fresh()->name);
        $this->assertSame(0, StockMovement::count());
    }
}
