<?php

namespace App\Notifications;

use App\Models\BusinessDocumentSignature;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A nudge for a signer who has had a request sitting in their inbox for a
 * while. Same link, gentler framing than the original ask — SignatureRequested
 * already made the ask; this only needs to say it is still waiting.
 */
class SignatureReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected BusinessDocumentSignature $signature,
        protected Company $company,
        protected int $daysWaiting,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->signature->document?->title ?? 'a document';

        return (new MailMessage)
            ->subject("Reminder: {$this->company->name} is still waiting on your signature")
            ->greeting('Hello '.($this->signature->signer_name ?: 'there').',')
            ->line("\"{$title}\" from {$this->company->name} is still waiting for your signature — "
                .($this->daysWaiting === 1 ? 'it has been a day.' : "it has been {$this->daysWaiting} days."))
            ->action('Review and sign', $this->signature->signingUrl())
            ->line('If you have already signed or this no longer applies, please ignore this message.')
            ->salutation("Kind regards,\n{$this->company->name}");
    }
}
