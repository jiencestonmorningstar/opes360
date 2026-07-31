<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the business owner when a platform admin reactivates a
 * previously-suspended account.
 */
class CompanyReactivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('opes.brand.name').' account is active again')
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line('Your business account on '.config('opes.brand.name').' has been reactivated. Everyone at your business can sign in again.')
            ->action('Sign in', url('/login'))
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Account reactivated',
            'body' => 'Your business account is active again.',
            'url' => '/',
        ];
    }
}
