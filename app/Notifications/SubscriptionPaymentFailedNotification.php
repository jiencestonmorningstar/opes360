<?php

namespace App\Notifications;

use App\Models\SubscriptionPayment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A subscription payment did not go through.
 *
 * The counterpart to SubscriptionPaymentSucceededNotification, which existed
 * alone: an owner who approved a prompt and heard nothing back cannot tell a
 * failure from a delay, and the most common reason — insufficient balance — is
 * one they can fix in a minute if somebody tells them.
 */
class SubscriptionPaymentFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected SubscriptionPayment $payment) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = Money::format($this->payment->amount, $this->payment->currency, false);
        $provider = $this->payment->provider === 'mtn_momo' ? 'MTN Mobile Money' : 'Orange Money';

        $mail = (new MailMessage)
            ->subject('Your '.config('opes.brand.name').' payment did not go through')
            ->greeting('That payment did not complete')
            ->line("We could not collect {$amount} for your ".ucfirst($this->payment->plan).' plan through '.$provider.'.');

        // The provider's own words are more use than ours: "insufficient
        // balance" tells the owner exactly what to do next.
        if ($this->payment->failure_reason) {
            $mail->line('The provider said: '.$this->payment->failure_reason);
        }

        return $mail
            ->line('Nothing has changed on your account and you have not been charged. You can try again whenever you are ready.')
            ->action('Try again', url('/settings/billing'))
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Subscription payment failed',
            'body' => Money::format($this->payment->amount, $this->payment->currency, false).' could not be collected'
                .($this->payment->failure_reason ? ' — '.$this->payment->failure_reason : ''),
            'url' => '/settings/billing',
        ];
    }
}
