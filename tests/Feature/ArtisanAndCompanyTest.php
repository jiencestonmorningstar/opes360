<?php

namespace Tests\Feature;

use App\Livewire\Business\Artisans;
use App\Livewire\Business\Companies;
use App\Models\Artisan;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\VerificationToken;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ArtisanAndCompanyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $this->company = $this->makeCompany('Acme Ltd', 'acme');
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function makeCompany(string $name, string $slug): Company
    {
        $company = Company::create([
            'slug' => $slug,
            'name' => $name,
            'owner_id' => $this->user->id,
            'currency' => 'USD',
        ]);

        $company->users()->attach($this->user->id, [
            'role_id' => Role::where('slug', 'owner')->value('id'),
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $company;
    }

    public function test_creating_an_artisan_mints_a_verification_token(): void
    {
        Livewire::actingAs($this->user)
            ->test(Artisans::class)
            ->call('startCreating')
            ->set('form.full_name', 'Emeka Nwosu')
            ->set('form.occupation', 'Master Electrician')
            ->set('form.skills', 'Wiring, Solar installation')
            ->set('form.phone', '+234 803 555 0101')
            ->call('save')
            ->assertHasNoErrors();

        $artisan = Artisan::firstOrFail();

        $this->assertSame('emeka-nwosu', $artisan->slug);
        $this->assertSame(['Wiring', 'Solar installation'], $artisan->skills);
        $this->assertTrue($artisan->is_verified);
        $this->assertNotNull($artisan->verification_token_id);
        $this->assertStringStartsWith('ART-', $artisan->artisan_number);
    }

    public function test_an_artisan_profile_is_public_and_a_scan_verifies_it(): void
    {
        Livewire::actingAs($this->user)
            ->test(Artisans::class)
            ->call('startCreating')
            ->set('form.full_name', 'Amina Bello')
            ->set('form.occupation', 'Carpenter')
            ->call('save');

        $artisan = Artisan::firstOrFail();
        $token = VerificationToken::where('subject_id', $artisan->id)->firstOrFail();

        // A public visitor: no session, no current company.
        app(CurrentCompany::class)->set(null);

        $this->get(route('profile.artisan', $artisan))
            ->assertOk()
            ->assertSee('Amina Bello')
            ->assertSee('Carpenter')
            ->assertSee('Verified artisan');

        $this->get("/v/{$token->token}")->assertOk()->assertSee('Verified authentic');
    }

    public function test_an_unpublished_artisan_is_hidden_from_the_public_url(): void
    {
        Livewire::actingAs($this->user)
            ->test(Artisans::class)
            ->call('startCreating')
            ->set('form.full_name', 'Hidden Person')
            ->set('form.is_published', false)
            ->call('save');

        $artisan = Artisan::firstOrFail();
        $token = VerificationToken::where('subject_id', $artisan->id)->firstOrFail();

        app(CurrentCompany::class)->set(null);

        $this->get(route('profile.artisan', $artisan))->assertNotFound();
        // The scan resolves but reports the profile is not live.
        $this->get("/v/{$token->token}")->assertOk()->assertSee('Voided');
    }

    public function test_the_artisan_vcard_downloads(): void
    {
        Livewire::actingAs($this->user)
            ->test(Artisans::class)
            ->call('startCreating')
            ->set('form.full_name', 'Emeka Nwosu')
            ->set('form.occupation', 'Electrician')
            ->set('form.phone', '+234 803 555 0101')
            ->call('save');

        $artisan = Artisan::firstOrFail();
        app(CurrentCompany::class)->set(null);

        $response = $this->get(route('profile.artisan.vcard', $artisan));

        $response->assertOk()->assertHeader('Content-Type', 'text/vcard; charset=utf-8');
        $this->assertStringContainsString('FN:Emeka Nwosu', $response->getContent());
        $this->assertStringContainsString('TITLE:Electrician', $response->getContent());
    }

    public function test_artisans_are_scoped_to_their_company(): void
    {
        $other = $this->makeCompany('Other Ltd', 'other');

        app(CurrentCompany::class)->as($other, fn () => Artisan::create([
            'slug' => 'foreign-artisan',
            'full_name' => 'Foreign Artisan',
        ]));

        // Back in Acme's context, the other company's artisan is invisible.
        $this->assertSame(0, Artisan::count());
        $this->assertSame(1, Artisan::query()->acrossAllCompanies()->count());
    }

    public function test_a_second_business_can_be_created_and_switched_to(): void
    {
        Livewire::actingAs($this->user)
            ->test(Companies::class)
            ->call('startCreating')
            ->set('newName', 'Acme Logistics')
            ->set('newCurrency', 'NGN')
            ->call('createCompany')
            ->assertHasNoErrors()
            ->assertRedirect(route('business'));

        $second = Company::where('name', 'Acme Logistics')->firstOrFail();

        $this->assertSame($second->id, $this->user->fresh()->current_company_id);
        $this->assertSame('NGN', $second->currency);
        // A new business gets its public identity immediately.
        $this->assertDatabaseHas('verification_tokens', [
            'subject_type' => Company::class,
            'subject_id' => $second->id,
        ]);

        // Switching back returns to the first business.
        Livewire::actingAs($this->user->fresh())
            ->test(Companies::class)
            ->call('switchTo', $this->company->id)
            ->assertRedirect(route('dashboard'));

        $this->assertSame($this->company->id, $this->user->fresh()->current_company_id);
    }

    public function test_switching_into_a_business_you_do_not_belong_to_is_refused(): void
    {
        $outsiderOwner = User::factory()->create();

        $foreign = Company::create([
            'slug' => 'foreign-co',
            'name' => 'Foreign Co',
            'owner_id' => $outsiderOwner->id,
        ]);

        Livewire::actingAs($this->user)
            ->test(Companies::class)
            ->call('switchTo', $foreign->id)
            ->assertHasErrors(['switch']);

        $this->assertSame($this->company->id, $this->user->fresh()->current_company_id);
    }
}
