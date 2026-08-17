<?php

namespace App\Notifications;

use App\Models\BusinessDocumentBundle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** §60 — the ZIP a background job built is ready to fetch. */
class DocumentBundleReadyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected BusinessDocumentBundle $bundle) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your document download is ready')
            ->line('The '.$this->bundle->document_count.'-file archive you requested has finished building.')
            ->line('Open the Documents workspace and look for it under Downloads to fetch it.');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_bundle_ready',
            'bundle_id' => $this->bundle->id,
            'document_count' => $this->bundle->document_count,
        ];
    }
}
