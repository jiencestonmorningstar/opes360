<?php

namespace App\Notifications;

use App\Models\NotificationDelivery;
use App\Support\NotificationCategories;
use App\Support\NotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Everything that was held back, in one message.
 *
 * The point of a digest is arithmetic: twelve interruptions in a morning get a
 * channel muted, and one summary at nine o'clock does not. It is the only
 * noise control that reduces the count without reducing what is said — mutes
 * lose information, deduplication loses repeats, this loses neither.
 *
 * Grouped by category rather than listed flat, so a person scanning it can
 * skip the half they do not own.
 */
class NotificationDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  Collection<int, NotificationDelivery>  $held */
    public function __construct(
        protected Collection $held,
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
            ->subject($this->headline())
            ->greeting($this->headline());

        foreach ($this->held->groupBy('category') as $category => $rows) {
            $mail->line('**'.NotificationCategories::label((string) $category).'**');

            foreach ($rows->take(10) as $row) {
                $mail->line('· '.$row->title.(filled($row->body) ? ' — '.$row->body : ''));
            }

            if ($rows->count() > 10) {
                $mail->line('· …and '.($rows->count() - 10).' more.');
            }
        }

        return $mail
            ->line('You are receiving these as a summary. Change that in your notification settings.')
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->headline(),
            'body' => $this->held->take(3)->pluck('title')->implode(' · ')
                .($this->held->count() > 3 ? ' and more' : ''),
            'url' => '/notifications',
            'category' => 'system',
            'digest' => true,
            'count' => $this->held->count(),
        ];
    }

    protected function headline(): string
    {
        $count = $this->held->count();

        return $count === 1 ? '1 update from your business' : "{$count} updates from your business";
    }
}
