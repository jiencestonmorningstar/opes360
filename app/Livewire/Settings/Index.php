<?php

namespace App\Livewire\Settings;

use App\Models\Device;
use App\Models\Role;
use App\Models\User;
use App\Services\TwoFactor;
use App\Support\CurrentCompany;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Index extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public bool $enrolling = false;

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

    public function revokeDevice(string $deviceId): void
    {
        // Scoped query: a device id from another company simply is not found.
        Device::query()->whereKey($deviceId)->update(['revoked_at' => now()]);

        session()->flash('deviceStatus', 'Device revoked. It can no longer sync.');
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
            'devices' => Device::query()->with('user')->latest('last_synced_at')->get(),
        ])->layout('components.layouts.app', ['title' => 'Settings', 'active' => 'settings']);
    }
}
