<?php

namespace App\Notifications;

use App\Services\Notifications\NotificationMessage;
use App\Support\NotificationCategories;
use App\Support\NotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A message a business asked for itself, through a notification rule.
 *
 * One notification class for every rule, because the wording is data — sixty
 * classes differing only in a string is the same mistake DomainEvent avoids.
 *
 * It carries exactly one channel per instance. The dispatcher already decided,
 * per channel, whether this person wants it, and a notification that fanned
 * itself out to mail and the bell together would undo that decision — somebody
 * who wanted the bell but not the email would get both.
 *
 * Queued, and it degrades: on a host with no worker the queue connection is
 * `sync` and this runs inline, which is slower and still correct. The
 * alternative — assuming a daemon — fails by going quiet, and a notification
 * system that fails quietly is the worst of the options.
 */
class RuleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected NotificationMessage $message,
        protected string $channel,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $driver = NotificationChannels::driver($this->channel);

        return $driver === null ? [] : [$driver];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->message->title)
            ->greeting($this->message->title);

        if (filled($this->message->body)) {
            $mail->line($this->message->body);
        }

        if (filled($this->message->url)) {
            $mail->action('Open it', url($this->message->url));
        }

        /*
         * Says plainly where it came from. An automated alert that reads as
         * though a colleague wrote it costs somebody a reply to nobody, and
         * the second one costs the business its trust in the first.
         */
        return $mail
            ->line('Sent automatically by a notification rule in your business settings.')
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->message->title,
            'body' => $this->message->body,
            'url' => $this->message->url,
            'event' => $this->message->event,
            'category' => $this->message->category,
            'category_label' => NotificationCategories::label($this->message->category),
            'severity' => $this->message->severity,
            'company_id' => $this->message->companyId,
        ];
    }
}
