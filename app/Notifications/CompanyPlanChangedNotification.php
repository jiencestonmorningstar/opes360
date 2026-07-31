<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the business owner when a platform admin changes their plan —
 * distinct from a self-service upgrade, so it's always worth telling them
 * even though nothing in the product flow required it.
 */
class CompanyPlanChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected string $from, protected string $to) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('opes.brand.name').' plan changed to '.ucfirst($this->to))
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line('Your business account on '.config('opes.brand.name').' has moved from the '.ucfirst($this->from).' plan to the '.ucfirst($this->to).' plan.')
            ->action('See what changed', url('/pricing'))
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Plan changed',
            'body' => 'Your plan changed from '.ucfirst($this->from).' to '.ucfirst($this->to).'.',
            'url' => '/pricing',
        ];
    }
}
