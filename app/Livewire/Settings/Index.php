<?php

namespace App\Livewire\Settings;

use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use App\Services\TeamInvitations;
use App\Services\TwoFactor;
use App\Support\CurrentCompany;
use App\Support\Modules;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;
use RuntimeException;

class Index extends Component
{
    use AuthorizesRequests;

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $enrolling = false;

    // ── Team ────────────────────────────────────────────────────────────
    public bool $inviting = false;

    public string $inviteEmail = '';

    public string $inviteRole = '';

    public string $inviteJobTitle = '';

    public string $twoFactorSecret = '';

    public string $twoFactorCode = '';

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
    }

    public function saveProfile(): void
    {
        $user = auth()->user();

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        $user->update([
            'name' => trim($this->name),
            'email' => strtolower(trim($this->email)),
            'phone' => $this->phone ?: null,
        ]);

        session()->flash('profileStatus', 'Profile updated.');
    }

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required'],
            'newPassword' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'newPassword.confirmed' => 'The two new passwords do not match.',
        ], [
            'newPassword' => 'new password',
        ]);

        if (! Hash::check($this->currentPassword, auth()->user()->password)) {
            $this->addError('currentPassword', 'That is not your current password.');

            return;
        }

        auth()->user()->update(['password' => $this->newPassword]);

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        session()->flash('passwordStatus', 'Password changed.');
    }

    public function startTwoFactor(TwoFactor $twoFactor): void
    {
        $this->twoFactorSecret = $twoFactor->startEnrolment(auth()->user());
        $this->enrolling = true;
        $this->twoFactorCode = '';
        $this->resetErrorBag();
    }

    public function confirmTwoFactor(TwoFactor $twoFactor): void
    {
        $this->validate(['twoFactorCode' => ['required', 'string']]);

        if (! $twoFactor->confirm(auth()->user(), $this->twoFactorCode)) {
            $this->addError('twoFactorCode', 'That code is not valid. Check your authenticator app.');

            return;
        }

        $this->reset('enrolling', 'twoFactorSecret', 'twoFactorCode');
        session()->flash('twoFactorStatus', 'Two-factor authentication is on. Save your recovery codes.');
    }

    public function cancelTwoFactor(TwoFactor $twoFactor): void
    {
        // A half-finished enrolment is discarded rather than left dangling, so a
        // stored-but-unconfirmed secret can never be treated as enabled.
        $twoFactor->disable(auth()->user());
        $this->reset('enrolling', 'twoFactorSecret', 'twoFactorCode');
    }

    public function disableTwoFactor(TwoFactor $twoFactor): void
    {
        $twoFactor->disable(auth()->user());
        session()->flash('twoFactorStatus', 'Two-factor authentication is off.');
    }

    /** Ends the demo clock early. Gated the same as everything else on this business. */
    public function endDemo(): void
    {
        $this->authorize('business.update');

        $company = app(CurrentCompany::class)->get();

        if ($company !== null && $company->isDemo()) {
            $company->endDemo();
        }
    }

    /**
     * Switch a module on or off for this business.
     *
     * Only the departure from the default is stored, so a module added in a
     * later release arrives switched on rather than silently missing. Turning
     * something off never deletes anything: the screens go quiet and the data
     * waits, which is what makes trying a module and changing your mind a
     * decision rather than a commitment.
     */
    public function toggleModule(string $key): void
    {
        $this->authorize('business.update');

        $company = app(CurrentCompany::class)->get();

        if ($company === null || ! Modules::exists($key)) {
            return;
        }

        if ((Modules::catalogue()[$key]['switchable'] ?? true) === false) {
            return;
        }

        $settings = (array) ($company->modules ?? []);
        $wasOn = Modules::enabled($company, $key);

        $settings[$key] = ! $wasOn;

        $company->forceFill(['modules' => $settings])->save();
        Modules::flush();

        $also = $wasOn
            ? array_filter(Modules::dependents($key), fn (string $k) => ! isset($settings[$k]) || $settings[$k])
            : [];

        session()->flash('moduleStatus', Modules::label($key).($wasOn ? ' switched off.' : ' switched on.').(
            $also === []
                ? ''
                : ' '.implode(' and ', array_map(fn (string $k) => Modules::label($k), $also)).
                  (count($also) === 1 ? ' went with it — it cannot work without this.' : ' went with it.')
        ));
    }

    public function revokeDevice(string $deviceId): void
    {
        $this->authorize('devices.revoke');

        // Scoped query: a device id from another company simply is not found.
        Device::query()->whereKey($deviceId)->update(['revoked_at' => now()]);

        session()->flash('deviceStatus', 'Device revoked. It can no longer sync.');
    }

    // ── Team ────────────────────────────────────────────────────────────

    public function startInviting(): void
    {
        $this->authorize('users.invite');

        $this->reset(['inviteEmail', 'inviteJobTitle']);
        $this->resetValidation();
        // Sales Officer: the role most people are invited as, and the one that
        // can do a day's work without being able to change what the business is.
        $this->inviteRole = (string) Role::where('slug', Role::SALES_OFFICER)->value('id');
        $this->inviting = true;
    }

    public function sendInvite(): void
    {
        $this->authorize('users.invite');

        $this->validate([
            'inviteEmail' => ['required', 'email', 'max:160'],
            'inviteRole' => ['required', 'exists:roles,id'],
            'inviteJobTitle' => ['nullable', 'string', 'max:80'],
        ], [
            'inviteEmail.required' => 'An email address is how the invitation reaches them.',
        ]);

        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return;
        }

        try {
            app(TeamInvitations::class)->invite(
                $company,
                $this->inviteEmail,
                Role::findOrFail($this->inviteRole),
                $this->inviteJobTitle ?: null,
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->inviting = false;
        session()->flash('teamStatus', 'Invitation sent to '.strtolower(trim($this->inviteEmail)).'.');
    }

    public function changeRole(int $userId, string $roleId): void
    {
        $this->authorize('users.update-role');

        $this->runTeamAction(
            fn (TeamInvitations $team, $company, User $member) => $team->changeRole(
                $company, $member, Role::findOrFail($roleId), auth()->user()
            ),
            $userId,
            'Role changed.'
        );
    }

    public function resendInvite(int $userId): void
    {
        $this->authorize('users.invite');

        $this->runTeamAction(
            fn (TeamInvitations $team, $company, User $member) => $team->resend($company, $member, auth()->user()),
            $userId,
            'Invitation sent again.'
        );
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('users.remove');

        $this->runTeamAction(
            function (TeamInvitations $team, $company, User $member) {
                $pending = $company->users()->where('users.id', $member->id)->first()?->pivot->status === 'invited';

                $pending
                    ? $team->cancelInvitation($company, $member, auth()->user())
                    : $team->remove($company, $member, auth()->user());
            },
            $userId,
            'Removed from the team.'
        );
    }

    /**
     * The shape every team action shares: find the member in *this* company,
     * do the thing, report what happened. Looking the member up through the
     * company rather than by id is what stops a crafted user id reaching
     * somebody else's staff.
     */
    protected function runTeamAction(callable $action, int $userId, string $success): void
    {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            return;
        }

        $member = $company->users()->where('users.id', $userId)->first();

        if ($member === null) {
            session()->flash('teamError', 'That person is not on this team.');

            return;
        }

        try {
            $action(app(TeamInvitations::class), $company, $member);
        } catch (RuntimeException $e) {
            session()->flash('teamError', $e->getMessage());

            return;
        }

        session()->flash('teamStatus', $success);
    }

    public function render(): View
    {
        $company = app(CurrentCompany::class)->get();

        $user = auth()->user();

        return view('livewire.settings.index', [
            'company' => $company,
            'twoFactorEnabled' => $user->hasTwoFactorEnabled(),
            'recoveryCodes' => $user->hasTwoFactorEnabled() ? $user->recoveryCodes() : [],
            'team' => $company
                ? User::query()
                    ->whereHas('companies', fn ($q) => $q->where('companies.id', $company->id))
                    ->with(['companies' => fn ($q) => $q->where('companies.id', $company->id)])
                    ->orderBy('name')
                    ->get()
                : collect(),
            'roles' => Role::orderBy('level')->get()->keyBy('id'),
            // Everything except Owner: ownership carries the billing and the
            // legal responsibility for what the books say, and handing it over
            // is not a dropdown. See TeamInvitations.
            'assignableRoles' => Role::where('slug', '!=', Role::OWNER)->orderBy('level')->get(),
            'devices' => Device::query()->with('user')->latest('last_synced_at')->get(),
            'modules' => Modules::switchable(),
            'enabledModules' => $company ? Modules::enabledFor($company) : [],
        ])->layout('components.layouts.app', ['title' => 'Settings', 'active' => 'settings']);
    }
}
