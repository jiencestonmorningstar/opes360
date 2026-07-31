<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the business owner when a platform admin suspends their account —
 * so they find out from an email, not by suddenly being locked out with no
 * explanation. The admin's internal reason (if any) is deliberately not
 * included here; it's a support note, not something written for the
 * business to read verbatim.
 */
class CompanySuspendedNotification extends Notification implements ShouldQueue
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
            ->subject('Your '.config('opes.brand.name').' account has been suspended')
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line('Your business account on '.config('opes.brand.name').' has been suspended. Nobody at your business can currently sign in.')
            ->line('Nothing has been deleted — this can be reversed.')
            ->line('If you believe this is a mistake, please contact us to resolve it.')
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Account suspended',
            'body' => 'Your business account has been suspended. Contact us to resolve it.',
            'url' => '/',
        ];
    }
}
