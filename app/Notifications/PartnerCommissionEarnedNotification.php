<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\PartnerCommission;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a secretariat when a business they enrolled pays for their plan.
 *
 * Worth sending every time rather than once a month: the recurring part of the
 * programme is the part partners do not believe until they have watched it
 * happen twice.
 */
class PartnerCommissionEarnedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PartnerCommission $commission,
        protected Company $source,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = Money::format($this->commission->amount, $this->commission->currency, false);
        $percent = rtrim(rtrim(number_format((float) $this->commission->rate * 100, 1), '0'), '.');

        return (new MailMessage)
            ->subject('You earned '.$amount.' from '.$this->source->name)
            ->greeting('Hello '.($notifiable->name ?: 'there').',')
            ->line($this->source->name.' just paid their '.config('opes.brand.name').' subscription.')
            ->line('Your '.$percent.'% share — '.$amount.' — has been added to your partner balance.')
            ->line('It will keep arriving for as long as they stay on a plan.')
            ->action('See your earnings', route('partners.earnings'))
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'partner.commission',
            'title' => 'Commission earned',
            'body' => Money::format($this->commission->amount, $this->commission->currency, false).' from '.$this->source->name,
            'url' => route('partners.earnings'),
        ];
    }
}
