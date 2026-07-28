<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Address verification mail.
 *
 * The link is a signed URL with an expiry, so it cannot be forged or replayed
 * indefinitely. Queued for the same reason as the reset mail — see that class.
 */
class VerifyEmail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm your email for '.config('opes.brand.name'))
            ->greeting('Welcome, '.($notifiable->name ?: 'there').'.')
            ->line('Confirm this address so we can send you receipts, reset links and anything else your business needs from us.')
            ->action('Confirm email address', $this->verificationUrl($notifiable))
            ->line('If you did not create an account, nothing further is needed — you can ignore this email.')
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    protected function verificationUrl(object $notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes((int) config('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'email_verification'];
    }
}
