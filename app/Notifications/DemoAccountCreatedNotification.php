<?php

namespace App\Notifications;

use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent once, at demo creation. Carries the password in plain text because
 * the account does not exist for the owner to retrieve it any other way —
 * the same trade-off an invite email makes.
 */
class DemoAccountCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(protected Company $company, protected string $password) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('opes.brand.name').' demo is ready')
            ->greeting('Welcome to '.config('opes.brand.name').', '.($notifiable->name ?: 'there').'.')
            ->line('Your demo account for **'.$this->company->name.'** is set up, with one sample of everything — a customer, a product, an invoice, a document, a form and an event — so you can see how each piece works.')
            ->line('Sign in with:')
            ->line('**Email:** '.$notifiable->email)
            ->line('**Password:** '.$this->password)
            ->action('Sign in', url('/login'))
            ->line('The demo runs for 14 days. Nothing is lost when it ends — your account simply moves to a free trial, or you can switch over yourself at any time from Settings.')
            ->salutation('— The '.config('opes.brand.vendor').' team');
    }
}
