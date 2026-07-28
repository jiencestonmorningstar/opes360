<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The two emails an account depends on. If either fails silently the user is
 * locked out with no way back in, so the details matter more than they look.
 */
class AccountMailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_request_sends_the_branded_notification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'owner@example.test']);

        $this->post('/forgot-password', ['email' => 'owner@example.test'])->assertRedirect();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            $this->assertStringContainsString(config('opes.brand.name'), $mail->subject);
            // The reset link must carry both halves, or the form cannot identify
            // which account it belongs to.
            $this->assertStringContainsString($notification->token, $mail->actionUrl);
            $this->assertStringContainsString(urlencode($user->email), $mail->actionUrl);

            return true;
        });
    }

    public function test_the_reset_mail_is_queued(): void
    {
        // Sending inside the request would let an attacker tell a real address
        // from a fake one by timing the response, which is precisely what the
        // "we've sent a link if that address exists" wording exists to prevent.
        $this->assertInstanceOf(ShouldQueue::class, new ResetPassword('token'));
        $this->assertInstanceOf(ShouldQueue::class, new VerifyEmail);
    }

    public function test_a_reset_request_for_an_unknown_address_sends_nothing_and_says_the_same(): void
    {
        Notification::fake();

        $response = $this->post('/forgot-password', ['email' => 'nobody@example.test']);

        Notification::assertNothingSent();
        // Identical outcome to the known-address case: no account enumeration.
        $response->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_the_verification_mail_carries_a_signed_expiring_link(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            $this->assertStringContainsString('signature=', $url);
            $this->assertStringContainsString('expires=', $url);
            // The hash proves the link was issued for the address it verifies.
            $this->assertStringContainsString(sha1($user->email), $url);

            return true;
        });
    }
}
