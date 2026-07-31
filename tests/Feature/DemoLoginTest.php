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
            ->assertSee('john@opesware.com');
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
