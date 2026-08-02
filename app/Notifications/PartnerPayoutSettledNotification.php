<?php

namespace App\Notifications;

use App\Models\PartnerPayout;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a payout request is closed, either way.
 *
 * A rejection is worth as much of a message as a payment: the partner's balance
 * has been held against a request all this time, and silence is
 * indistinguishable from a request nobody looked at.
 */
class PartnerPayoutSettledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected PartnerPayout $payout) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = Money::format($this->payout->amount, $this->payout->currency, false);
        $paid = $this->payout->status === 'paid';

        $message = (new MailMessage)
            ->subject($paid ? 'Your payout of '.$amount.' is on its way' : 'About your payout request')
            ->greeting('Hello '.($notifiable->name ?: 'there').',');

        if ($paid) {
            $message->line('We have sent '.$amount.' to '.($this->payout->destination ?: 'the account you gave us').'.')
                ->line('Mobile money can take a few minutes to arrive.');
        } else {
            $message->line('We were not able to process your payout request for '.$amount.'.')
                ->line('The amount is back in your available balance, so you can request it again.');
        }

        if ($this->payout->note) {
            $message->line('Note: '.$this->payout->note);
        }

        return $message
            ->action('See your earnings', route('partners.earnings'))
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'partner.payout',
            'title' => $this->payout->status === 'paid' ? 'Payout sent' : 'Payout not processed',
            'body' => Money::format($this->payout->amount, $this->payout->currency, false),
            'url' => route('partners.earnings'),
        ];
    }
}
