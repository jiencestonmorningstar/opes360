<?php

namespace App\Notifications;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A payment was recorded against a document. Mail and in-app, both from the
 * same fact — SMTP is the only transport this product uses for either.
 */
class PaymentReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Payment $payment) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = Money::format($this->payment->amount, $this->payment->currency);

        return (new MailMessage)
            ->subject('Payment received: '.$amount)
            ->greeting('Payment received')
            ->line($amount.' was recorded from '.($this->payment->contact?->displayName() ?? 'a customer').'.')
            ->action('View payment', url('/payments'))
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Payment received',
            'body' => Money::format($this->payment->amount, $this->payment->currency).' from '.($this->payment->contact?->displayName() ?? 'a customer'),
            'url' => '/payments',
        ];
    }
}
