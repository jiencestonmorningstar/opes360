<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The first message a new business gets.
 *
 * Registration previously sent nothing at all, which leaves an owner with no
 * record of the address they signed up with and nothing to search for later.
 * Deliberately short: three things worth doing on day one, not a tour.
 */
class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Company $company) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Welcome to '.config('opes.brand.name').' — '.$this->company->name.' is ready')
            ->greeting('Welcome, '.$notifiable->name)
            ->line($this->company->name.' is set up and ready to use.')
            ->line('Three things worth doing first:')
            ->line('**1.** Add your business details — address, phone and tax numbers appear on every invoice you issue.')
            ->line('**2.** Add a few products or services, so invoicing is a matter of picking from a list.')
            ->line('**3.** Issue a test invoice and print it. Every document carries a QR code your customers can scan to verify it.')
            ->action('Open your dashboard', url('/'))
            ->line('If anything is unclear, reply to this message — it reaches a person.')
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Welcome to '.config('opes.brand.name'),
            'body' => $this->company->name.' is ready. Start by completing your business details.',
            'url' => '/business',
        ];
    }
}
