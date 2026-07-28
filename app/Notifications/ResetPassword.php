<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset mail.
 *
 * Queued: a slow or unreachable SMTP host must not hold a web request open,
 * and the reset endpoint deliberately answers identically whether or not the
 * address exists — a synchronous send would leak that difference through
 * response timing alone.
 *
 * Requires a running queue worker. See docs/DEPLOYMENT.md.
 */
class ResetPassword extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Reset your '.config('opes.brand.name').' password')
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line('We received a request to reset the password for your '.config('opes.brand.name').' account.')
            ->action('Choose a new password', $this->resetUrl($notifiable))
            ->line("This link expires in {$minutes} minutes.")
            // Said plainly, because the honest answer to "I didn't ask for this"
            // is that nothing has happened yet and nothing will.
            ->line('If you did not request a reset, you can ignore this email — your password will not change.')
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    protected function resetUrl(object $notifiable): string
    {
        return url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'password_reset'];
    }
}
