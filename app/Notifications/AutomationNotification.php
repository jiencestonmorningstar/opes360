<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A message a business asked for itself, through an automation rule.
 *
 * The wording comes from whoever wrote the rule, so this class supplies none
 * of its own beyond a subject line. It carries the event name alongside, so a
 * recipient can tell an automated message from a hand-written one — an alert
 * that looks like a colleague wrote it is worse than one that says plainly
 * where it came from.
 */
class AutomationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $message,
        protected string $event,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->message)
            ->line($this->message)
            ->line('Sent automatically by a rule in your business settings.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'automation',
            'event' => $this->event,
            'message' => $this->message,
        ];
    }
}
