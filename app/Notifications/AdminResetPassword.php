<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset mail for platform staff — a separate class from
 * App\Notifications\ResetPassword because that one points at the business
 * password.reset route and the 'users' broker's expiry; this one points at
 * the admin.* routes and the 'platform_admins' broker instead. Same
 * queue-and-answer-identically rationale as the business version.
 */
class AdminResetPassword extends Notification implements ShouldQueue
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
        $minutes = config('auth.passwords.platform_admins.expire');

        return (new MailMessage)
            ->subject('Reset your '.config('opes.brand.name').' platform admin password')
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line('We received a request to reset the password for your platform admin account.')
            ->action('Choose a new password', $this->resetUrl($notifiable))
            ->line("This link expires in {$minutes} minutes.")
            ->line('If you did not request a reset, you can ignore this email — your password will not change.')
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    protected function resetUrl(object $notifiable): string
    {
        return url(route('admin.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], absolute: false));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'admin_password_reset'];
    }
}
