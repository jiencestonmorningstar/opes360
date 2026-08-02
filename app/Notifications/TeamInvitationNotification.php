<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Services\TeamInvitations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Come and work in our books."
 *
 * Says who is inviting and to do what, because an unexplained link asking for
 * a password is indistinguishable from a phishing attempt — and the people
 * being invited here are shopkeepers and accountants who are right to be
 * suspicious of exactly that.
 */
class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected Company $company,
        protected User $invitedBy,
        protected Role $role,
        protected string $token,
        protected bool $needsPassword,
    ) {}

    /**
     * Mail only, deliberately. A database notification would land in an
     * in-product inbox the invited person cannot reach — they have no account
     * to open it with, which is the whole reason they are being emailed.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->invitedBy->name.' has invited you to '.$this->company->name)
            ->greeting('Hello')
            ->line($this->invitedBy->name.' has invited you to work in **'.$this->company->name.'** on '
                .config('opes.brand.name').', as **'.$this->role->name.'**.');

        $mail->line($this->needsPassword
            ? 'Opening the link below sets up your account — you choose your own password — and takes you straight in.'
            : 'You already have an account with this address. Opening the link below adds '
                .$this->company->name.' to it; your password does not change.');

        return $mail
            ->action('Accept the invitation', route('invitations.show', $this->token))
            ->line('The link works for '.TeamInvitations::VALID_FOR_DAYS.' days. After that ask '
                .$this->invitedBy->name.' to send another.')
            ->line('If you were not expecting this, ignore it — nothing happens until somebody opens the link.')
            ->salutation('— '.config('opes.brand.name'));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Invitation to '.$this->company->name,
            'body' => $this->invitedBy->name.' invited you as '.$this->role->name.'.',
        ];
    }
}
