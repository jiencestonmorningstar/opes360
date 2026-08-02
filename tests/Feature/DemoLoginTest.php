<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The demo buttons are ordinary POSTs of seeded credentials through the real
 * login flow, so what needs proving is the rendering switch and that no new
 * authentication path exists to audit.
 */
class DemoLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_buttons_render_when_enabled(): void
    {
        config(['opes.demo.enabled' => true]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('Just looking around?')
            ->assertSee('Business Owner')
            ->assertSee('Sales Officer')
            ->assertSee('Secretariat')
            ->assertSee('john@opesware.com');
    }

    /**
     * Every seeded demo account must be reachable from this page.
     *
     * The secretariat was seeded in full — client book, issued cards, earnings,
     * staff and a paid payroll run — and offered nowhere, so the only way in
     * was knowing the address by heart. A demo account nobody can reach is a
     * demo account that does not exist, and nothing would have failed to say
     * so. This is what says so.
     */
    public function test_every_seeded_demo_account_is_offered(): void
    {
        $offered = collect(config('opes.demo.accounts'))->pluck('email');

        foreach (['john@opesware.com', 'sales@opesware.com', 'secretariat@opesware.com'] as $email) {
            $this->assertTrue($offered->contains($email), sprintf(
                'The seeders build %s but the login page never offers it.', $email
            ));
        }
    }

    public function test_demo_buttons_hidden_when_disabled(): void
    {
        config(['opes.demo.enabled' => false]);

        $this->get('/login')
            ->assertOk()
            ->assertDontSee('Just looking around?')
            ->assertDontSee('john@opesware.com');
    }

    public function test_demo_credentials_sign_in_through_the_normal_flow(): void
    {
        $user = User::factory()->create([
            'email' => 'john@opesware.com',
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'email' => 'john@opesware.com',
            'password' => 'password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }
}
