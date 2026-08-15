<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\Document;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A reminder to a customer that an invoice is past due.
 *
 * Written to be sendable without embarrassment. It goes out under the
 * business's name to its own customer, and the tone at seven days late has to
 * survive the case where the customer paid in cash last week and nobody
 * recorded it — which, in this market, is most of the awkward cases.
 *
 * So: the facts, the amount, and an acknowledgement that it may already be
 * settled. Nothing threatening, at any rung. A business that wants to escalate
 * further should pick up the phone, and no email we generate can do that job
 * for them.
 */
class InvoiceOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Document $document,
        protected Company $company,
        protected int $step,
        protected int $daysOverdue,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = Money::format((float) $this->document->balance, $this->document->currency ?? 'XAF');
        $number = $this->document->number ?? 'your invoice';

        return (new MailMessage)
            ->subject("Reminder: invoice {$number} from {$this->company->name}")
            ->greeting('Hello,')
            ->line("This is a reminder that invoice {$number} for {$amount} was due on "
                .$this->document->due_date?->format('j F Y').'.')
            ->line($this->daysOverdue === 1
                ? 'It is one day past due.'
                : "It is {$this->daysOverdue} days past due.")
            // The line that makes this sendable. Recording a cash payment late
            // is normal, and a reminder that ignores the possibility reads as
            // an accusation.
            ->line('If you have already paid, please ignore this message — and thank you.')
            ->salutation("Kind regards,\n{$this->company->name}");
    }
}
