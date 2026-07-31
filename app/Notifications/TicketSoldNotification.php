<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketSoldNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Ticket $ticket) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ticket sold: '.$this->ticket->event?->title)
            ->greeting('New ticket sold')
            ->line($this->ticket->buyer_name.' bought a '.$this->ticket->ticketType?->name.' ticket for '.$this->ticket->event?->title.'.')
            ->action('View event', url('/events/'.$this->ticket->event_id))
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Ticket sold',
            'body' => $this->ticket->buyer_name.' — '.$this->ticket->event?->title,
            'url' => '/events/'.$this->ticket->event_id,
        ];
    }
}
