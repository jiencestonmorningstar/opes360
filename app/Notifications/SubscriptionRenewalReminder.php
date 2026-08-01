<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the business owner when their plan's billing period is about to end
 * ('upcoming', a week out) and again once it has ('overdue').
 *
 * Deliberately not a threat: nothing is switched off when the date passes —
 * that is a documented decision, a paid plan stays active until an admin or a
 * new payment changes it — so the overdue mail says the account is behind,
 * not that it is locked.
 */
class SubscriptionRenewalReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $plan,
        protected CarbonInterface $renewsAt,
        protected string $stage,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plan = ucfirst($this->plan);
        $date = $this->renewsAt->format('j F Y');

        if ($this->stage === 'overdue') {
            return (new MailMessage)
                ->subject('Your '.config('opes.brand.name').' '.$plan.' plan renewal is overdue')
                ->greeting('Hello '.($notifiable->name ?: 'there').',')
                ->line("Your {$plan} plan's billing period ended on {$date} and has not been renewed yet.")
                ->line('Your account is still active. Renew with MTN Mobile Money or Orange Money to keep it in good standing.')
                ->action('Renew now', url('/settings/billing'))
                ->salutation('— The '.config('opes.brand.vendor').' team');
        }

        return (new MailMessage)
            ->subject('Your '.config('opes.brand.name').' '.$plan.' plan renews on '.$date)
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line("Your {$plan} plan's current billing period ends on {$date}.")
            ->line('Renew in a minute with MTN Mobile Money or Orange Money.')
            ->action('Renew now', url('/settings/billing'))
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->stage === 'overdue' ? 'Plan renewal overdue' : 'Plan renewal due soon',
            'body' => ucfirst($this->plan).' plan '.($this->stage === 'overdue' ? 'ended' : 'ends').' '.$this->renewsAt->format('j M Y').'.',
            'url' => '/settings/billing',
        ];
    }
}
