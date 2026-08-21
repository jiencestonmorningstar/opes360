<?php

namespace App\Notifications;

use App\Models\BusinessDocumentSignature;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Please sign this" — the mail a signer needs, that nothing sent before
 * this existed. `DocumentSignatureRequests::request()` used to open a round
 * and leave every signer to somehow already know their link; this is what
 * actually tells them.
 *
 * Addressed on-demand (`Notification::route('mail', $signature->signer_email)`)
 * rather than through a User — most signers are outside the business
 * entirely (a counterparty, a witness) and have no account here at all.
 */
class SignatureRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected BusinessDocumentSignature $signature,
        protected Company $company,
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
            ->subject("{$this->company->name} — please sign: {$title}")
            ->greeting('Hello '.($this->signature->signer_name ?: 'there').',')
            ->line("{$this->company->name} has sent you \"{$title}\" to sign.")
            ->action('Review and sign', $this->signature->signingUrl())
            ->line('If you were not expecting this, you can safely ignore this message.')
            ->salutation("Kind regards,\n{$this->company->name}");
    }
}
