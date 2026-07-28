<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('user@example.com|127.0.0.1');

        $this->user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => 'correct-horse',
        ]);
    }

    public function test_a_user_can_sign_in(): void
    {
        $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_bad_credentials_are_rejected_without_revealing_which_part_was_wrong(): void
    {
        $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();

        // The same message for an unknown address, so accounts cannot be enumerated.
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    public function test_repeated_failures_are_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'user@example.com', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse'])
            ->assertSessionHasErrors('email');

        // Even the correct password is refused while the lockout holds.
        $this->assertGuest();
    }

    public function test_two_factor_blocks_the_session_until_the_code_is_entered(): void
    {
        $twoFactor = app(TwoFactor::class);
        $secret = $twoFactor->startEnrolment($this->user);
        $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse'])
            ->assertRedirect(route('two-factor.challenge'));

        // Credentials alone must not establish the session.
        $this->assertGuest();

        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->post('/two-factor', ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->user->fresh());
    }

    public function test_a_wrong_two_factor_code_is_rejected(): void
    {
        app(TwoFactor::class)->startEnrolment($this->user);
        $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse']);

        $this->post('/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once(): void
    {
        $twoFactor = app(TwoFactor::class);
        $twoFactor->startEnrolment($this->user);
        $this->user->forceFill(['two_factor_confirmed_at' => now()])->save();

        $code = $this->user->fresh()->recoveryCodes()[0];

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse']);
        $this->post('/two-factor', ['code' => $code])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // The same code cannot be reused.
        $this->post('/logout');
        RateLimiter::clear('user@example.com|127.0.0.1');
        $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse']);
        $this->post('/two-factor', ['code' => $code])->assertSessionHasErrors('code');
    }

    public function test_the_challenge_page_is_not_reachable_without_passing_credentials(): void
    {
        $this->get('/two-factor')->assertForbidden();
    }

    public function test_the_two_factor_secret_is_encrypted_at_rest(): void
    {
        $secret = app(TwoFactor::class)->startEnrolment($this->user);

        $raw = \DB::table('users')->where('id', $this->user->id)->value('two_factor_secret');

        $this->assertNotSame($secret, $raw);
        $this->assertSame($secret, Crypt::decryptString($raw));
    }

    public function test_an_unconfirmed_enrolment_does_not_count_as_enabled(): void
    {
        app(TwoFactor::class)->startEnrolment($this->user);

        // A secret exists but was never confirmed — sign-in must not demand it,
        // or a half-finished setup would lock the user out.
        $this->assertFalse($this->user->fresh()->hasTwoFactorEnabled());

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'correct-horse'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_password_reset_link_is_emailed_and_works(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'user@example.com'])->assertSessionHas('status');

        Notification::assertSentTo($this->user, ResetPassword::class, function (ResetPassword $notification) {
            $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => 'user@example.com',
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertRedirect(route('login'));

            return true;
        });

        RateLimiter::clear('user@example.com|127.0.0.1');

        $this->post('/login', ['email' => 'user@example.com', 'password' => 'a-brand-new-password'])
            ->assertRedirect(route('dashboard'));
    }

    public function test_an_unknown_address_gets_the_same_reset_response(): void
    {
        Notification::fake();

        // Identical response, so the endpoint cannot be used to test whether an
        // address has an account.
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])
            ->assertSessionHas('status', 'If that address has an account, a reset link is on its way.');

        Notification::assertNothingSent();
    }

    public function test_the_two_factor_qr_only_renders_the_signed_in_users_own_secret(): void
    {
        app(TwoFactor::class)->startEnrolment($this->user);

        $response = $this->actingAs($this->user)->get(route('two-factor.qr'));
        $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');

        // A user with no enrolment in progress gets nothing to render.
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('two-factor.qr'))->assertNotFound();
    }
}
