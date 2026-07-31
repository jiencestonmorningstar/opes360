<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A message submitted through the public contact form. Routed to an anonymous
 * notifiable (config('opes.contact.recipient')) rather than a User — the
 * recipient is an admin mailbox, not an account in the system.
 */
class ContactMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $name,
        protected string $email,
        protected string $message,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New contact message — '.config('opes.brand.name'))
            ->greeting('New message from the contact form')
            ->line('**From:** '.$this->name.' ('.$this->email.')')
            ->line('**Message:**')
            ->line($this->message)
            ->salutation('— '.config('opes.brand.name'));
    }
}
