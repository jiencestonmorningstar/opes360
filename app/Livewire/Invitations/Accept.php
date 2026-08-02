<?php

namespace App\Livewire\Invitations;

use App\Models\Company;
use App\Models\User;
use App\Services\TeamInvitations;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use RuntimeException;

/**
 * The other end of an invitation.
 *
 * Reachable without logging in, because the person opening it may have no
 * account yet — that is what they are here to finish. It shows which business
 * and who invited them before it asks for anything, since a page that opens by
 * demanding a password is the shape of every phishing attempt anyone has ever
 * received.
 */
class Accept extends Component
{
    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    /** Null when the link is unknown, spent or out of date. */
    public ?Company $company = null;

    public ?User $invitee = null;

    public bool $needsPassword = false;

    public function mount(string $token): void
    {
        $this->token = $token;

        $found = app(TeamInvitations::class)->findByToken($token);

        if ($found === null) {
            return;
        }

        $this->company = $found['company'];
        $this->invitee = $found['user'];
        $this->needsPassword = $found['user']->password === null;
        $this->name = $this->needsPassword ? '' : $found['user']->name;
    }

    public function accept(): void
    {
        if ($this->company === null) {
            return;
        }

        if ($this->needsPassword) {
            $this->validate([
                'name' => ['required', 'string', 'max:120'],
                'password' => ['required', 'string', 'min:8', 'same:passwordConfirmation'],
            ], [
                'name.required' => 'Tell us what to call you.',
                'password.min' => 'Eight characters or more.',
                'password.same' => 'The two passwords do not match.',
            ]);
        }

        try {
            $user = app(TeamInvitations::class)->accept($this->token, [
                'name' => $this->name,
                'password' => $this->password,
            ]);
        } catch (RuntimeException $e) {
            $this->addError('token', $e->getMessage());

            return;
        }

        /*
         * Somebody who just set their password is logged straight in: they have
         * proved they hold the address and have chosen a secret, which is
         * everything the login form would ask for. An existing user is sent to
         * the login form instead — accepting from a forwarded link must not be
         * a way into somebody else's account.
         */
        if ($this->needsPassword) {
            Auth::login($user);
            session()->regenerate();

            $this->redirectRoute('dashboard', navigate: false);

            return;
        }

        session()->flash('status', 'You have joined '.$this->company->name.'. Sign in to open it.');
        $this->redirectRoute('login', navigate: false);
    }

    public function render(): View
    {
        return view('livewire.invitations.accept')
            ->layout('components.layouts.public', ['title' => 'Invitation']);
    }
}
