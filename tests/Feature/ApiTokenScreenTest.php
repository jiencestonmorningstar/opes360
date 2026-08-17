<?php

namespace Tests\Feature;

use App\Livewire\Settings\ApiTokens;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\TokenAbilities;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Minting and revoking tokens from the app.
 *
 * This screen is what makes the API safe to hand to anyone: without it the
 * only way to get a token is to POST an email and password, which means giving
 * an integration the credentials to the whole account — including the ability
 * to change the password and lock the owner out.
 */
class ApiTokenScreenTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();
        $company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->user->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($company, $this->user, Role::OWNER);
        $this->user->forceFill(['current_company_id' => $company->id])->save();

        app(CurrentCompany::class)->set($company);
    }

    public function test_the_screen_renders(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.api-tokens'))
            ->assertOk()
            ->assertSee('API tokens');
    }

    public function test_a_token_can_be_created_with_chosen_scopes(): void
    {
        Livewire::actingAs($this->user)
            ->test(ApiTokens::class)
            ->set('name', 'Stock sync')
            ->set('abilities', [TokenAbilities::READ, TokenAbilities::WRITE])
            ->call('create')
            ->assertHasNoErrors();

        $token = $this->user->tokens()->firstOrFail();

        $this->assertSame('Stock sync', $token->name);
        $this->assertSame(['read', 'write'], $token->abilities);
    }

    /**
     * Shown once and never again: only a hash is kept, so a screen that could
     * show it later would mean it had been stored in the clear.
     */
    public function test_the_token_is_shown_once_and_then_dismissed(): void
    {
        $component = Livewire::actingAs($this->user)
            ->test(ApiTokens::class)
            ->set('name', 'Stock sync')
            ->call('create');

        $plain = $component->get('plainTextToken');

        $this->assertNotNull($plain);
        $component->assertSee($plain);

        $component->call('dismissToken')->assertSet('plainTextToken', null);
    }

    public function test_a_token_needs_a_name_and_at_least_one_scope(): void
    {
        Livewire::actingAs($this->user)
            ->test(ApiTokens::class)
            ->set('name', '')
            ->set('abilities', [])
            ->call('create')
            ->assertHasErrors(['name', 'abilities']);
    }

    public function test_a_token_can_be_revoked(): void
    {
        $id = $this->user->createToken('Stock sync', ['read'])->accessToken->getKey();

        Livewire::actingAs($this->user)
            ->test(ApiTokens::class)
            ->call('revoke', $id);

        $this->assertSame(0, $this->user->tokens()->count());
    }

    /** Somebody else's token id must not resolve here. */
    public function test_one_user_cannot_revoke_anothers_token(): void
    {
        $stranger = User::factory()->create();
        $theirs = $stranger->createToken('Theirs', ['read'])->accessToken->getKey();

        Livewire::actingAs($this->user)
            ->test(ApiTokens::class)
            ->call('revoke', $theirs);

        $this->assertSame(1, $stranger->tokens()->count());
    }

    /** A token minted here must actually work against the API. */
    public function test_a_token_minted_here_authenticates_against_the_api(): void
    {
        $plain = Livewire::actingAs($this->user)
            ->test(ApiTokens::class)
            ->set('name', 'Reporting')
            ->set('abilities', [TokenAbilities::READ])
            ->call('create')
            ->get('plainTextToken');

        /*
         * Drop the session the component ran under. Sanctum prefers a
         * session-authenticated user when one is present and gives it a
         * TransientToken, which allows every ability — so without this the
         * assertions below would pass on the session and prove nothing about
         * the token.
         */
        Auth::logout();
        $this->flushSession();

        $this->getJson('/api/v1/contacts', ['Authorization' => 'Bearer '.$plain])
            ->assertOk();

        // And is held to the scope it was given.
        $this->postJson('/api/v1/contacts', ['name' => 'Nope'], ['Authorization' => 'Bearer '.$plain])
            ->assertForbidden();
    }

    /**
     * Minting a credential is account configuration, gated on
     * `settings.update` like the webhooks screen next to it. A token outlives
     * a password change, so a role that may only look at settings must not be
     * able to arrange standing API access for itself.
     */
    public function test_a_role_without_settings_update_cannot_reach_the_screen(): void
    {
        $clerk = User::factory()->create();
        $company = Company::query()->firstOrFail();

        $this->joinCompany($company, $clerk, Role::SALES_OFFICER);
        $clerk->forceFill(['current_company_id' => $company->id])->save();

        $this->actingAs($clerk)
            ->get(route('settings.api-tokens'))
            ->assertForbidden();

        Livewire::actingAs($clerk)
            ->test(ApiTokens::class)
            ->assertForbidden();
    }
}
