<?php

namespace App\Notifications;

use App\Models\SubscriptionPayment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the business owner once a mobile money payment for a plan is
 * confirmed and the plan is live — the receipt for a self-service purchase,
 * as distinct from CompanyPlanChangedNotification which covers an admin
 * changing the plan by hand.
 */
class SubscriptionPaymentSucceededNotification extends Notification implements ShouldQueue
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
        $plan = ucfirst($this->payment->plan);
        $provider = $this->payment->provider === 'mtn_momo' ? 'MTN Mobile Money' : 'Orange Money';

        return (new MailMessage)
            ->subject('Payment received — you\'re on the '.$plan.' plan')
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line('We received your '.Money::format($this->payment->amount, $this->payment->currency, false).' payment via '.$provider.'.')
            ->line('Your business account on '.config('opes.brand.name').' is now on the '.$plan.' plan.')
            ->action('Go to dashboard', url('/'))
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Payment received',
            'body' => 'Your '.ucfirst($this->payment->plan).' plan is now active.',
            'url' => '/settings/billing',
        ];
    }
}
