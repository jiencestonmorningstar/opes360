<?php

namespace App\Notifications;

use App\Models\Form;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FormResponseReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Form $form) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New response: '.$this->form->title)
            ->greeting('New form response')
            ->line('Someone just filled in "'.$this->form->title.'".')
            ->action('View responses', url('/forms/'.$this->form->id.'/responses'))
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New form response',
            'body' => $this->form->title,
            'url' => '/forms/'.$this->form->id.'/responses',
        ];
    }
}
