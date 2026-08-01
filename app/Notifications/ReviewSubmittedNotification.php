<?php

namespace App\Notifications;

use App\Models\CompanyReview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A customer left a review on the public profile.
 *
 * Reviews are stored unpublished and appear only once the business approves
 * them, so without this nobody would know one was waiting — a queue that fills
 * up silently is the same as a feature nobody uses.
 */
class ReviewSubmittedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected CompanyReview $review) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $stars = str_repeat('★', $this->review->rating).str_repeat('☆', 5 - $this->review->rating);

        return (new MailMessage)
            ->subject('New review awaiting your approval')
            ->greeting('A customer left you a review')
            ->line($stars.' — from '.$this->review->author_name)
            ->line('"'.Str::limit($this->review->body, 300).'"')
            ->line('It is not visible on your public profile yet. Nothing appears there until you approve it.')
            ->action('Review it', url('/business/reviews'))
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New review awaiting approval',
            'body' => $this->review->rating.'★ from '.$this->review->author_name,
            'url' => '/business/reviews',
        ];
    }
}
