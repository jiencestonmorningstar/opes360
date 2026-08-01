@php
    use App\Support\Accent;

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div>
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Settings</h1>
        <p class="mt-1 text-[14.5px] text-muted">Your account, your team, your devices.</p>
    </div>

    {{-- Demo account (start/end delimited) --}}
    @if ($company?->isDemo())
        <div class="card mt-5 flex flex-wrap items-center justify-between gap-3 border-brand/30 bg-tint-blue p-5">
            <div>
                <p class="text-[14.5px] font-bold text-ink">You're on a demo account</p>
                <p class="mt-0.5 text-[13px] text-ink-2">
                    @php $days = $company->demoDaysLeft(); @endphp
                    {{ $days === null ? 'Runs for 14 days, then moves to a free trial automatically.' : ($days.' '.\Illuminate\Support\Str::plural('day', $days).' left, then it moves to a free trial automatically.') }}
                </p>
            </div>
            @can('business.update')
                <button type="button" wire:click="endDemo" wire:confirm="End the demo now and start your free trial?"
                        class="tap focusable shrink-0 rounded-xl bg-brand px-4 py-2.5 text-[13.5px] font-semibold text-white transition-opacity hover:opacity-90">
                    End demo & start free trial
                </button>
            @endcan
        </div>
    @endif
    {{-- /Demo account --}}

    <div class="mt-5 grid gap-4 lg:grid-cols-2">

        {{-- Profile --}}
        <x-ui.panel title="Your Profile">
            @if (session('profileStatus'))
                <div class="mb-4 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
                    {{ session('profileStatus') }}
                </div>
            @endif

            <div class="space-y-4">
                <label class="block">
                    <span class="{{ $labelClass }}">Name</span>
                    <input type="text" wire:model="name" class="{{ $inputClass }}">
                    @error('name') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Email</span>
                    <input type="email" wire:model="email" class="{{ $inputClass }}">
                    @error('email') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>
                <label class="block">
                    <span class="{{ $labelClass }}">Phone</span>
                    <input type="tel" wire:model="phone" class="{{ $inputClass }}">
                </label>
            </div>

            <button type="button" wire:click="saveProfile"
                    class="focusable mt-4 flex h-11 w-full items-center justify-center rounded-xl bg-brand text-[14.5px] font-semibold text-white hover:opacity-90">
                Save Profile
            </button>
        </x-ui.panel>

        {{-- Appearance + password --}}
        <div class="space-y-4">
            <x-ui.panel title="Appearance">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <p class="text-[14.5px] font-semibold text-ink">Theme</p>
                        <p class="text-[12.5px] text-muted">Follows your device unless you choose one.</p>
                    </div>
                    <button type="button" @click="toggleTheme()"
                            class="focusable flex h-11 items-center gap-2 rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-ink-2 hover:bg-surface-2">
                        <x-icon name="sun" class="size-[18px] text-muted dark:hidden" />
                        <x-icon name="moon" class="hidden size-[18px] text-muted dark:block" />
                        <span class="dark:hidden">Switch to dark</span>
                        <span class="hidden dark:inline">Switch to light</span>
                    </button>
                </div>
            </x-ui.panel>


            <x-ui.panel title="Two-Factor Authentication">
                @if (session('twoFactorStatus'))
                    <div class="mb-4 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
                        {{ session('twoFactorStatus') }}
                    </div>
                @endif

                @if ($enrolling)
                    <p class="text-[13.5px] leading-relaxed text-muted">
                        Scan this with Google Authenticator, 1Password or any TOTP app, then enter the
                        6-digit code it shows to finish.
                    </p>

                    <div class="mt-4 flex flex-col items-center rounded-xl border border-border p-4">
                        <img src="{{ route('two-factor.qr') }}" alt="Two-factor setup QR"
                             class="size-[168px] rounded-lg bg-white p-2">
                        <p class="mt-3 text-[12px] text-muted">Can't scan? Enter this key manually:</p>
                        <p class="tnum mt-1 select-all break-all text-center text-[13px] font-semibold text-ink">
                            {{ $twoFactorSecret }}
                        </p>
                    </div>

                    <label class="mt-4 block">
                        <span class="{{ $labelClass }}">6-digit code</span>
                        <input type="text" inputmode="numeric" wire:model="twoFactorCode" placeholder="000000"
                               class="tnum h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-center text-[17px] tracking-[0.3em] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                        @error('twoFactorCode') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>

                    <div class="mt-4 flex gap-3">
                        <button type="button" wire:click="cancelTwoFactor"
                                class="focusable h-11 flex-1 rounded-xl bg-surface-2 text-[14.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                            Cancel
                        </button>
                        <button type="button" wire:click="confirmTwoFactor"
                                class="focusable h-11 flex-[1.4] rounded-xl bg-brand text-[14.5px] font-semibold text-white hover:opacity-90">
                            Turn on
                        </button>
                    </div>
                @elseif ($twoFactorEnabled)
                    <div class="flex items-center gap-3">
                        <span class="flex size-[42px] shrink-0 items-center justify-center rounded-xl bg-tint-green">
                            <x-icon name="check-circle" class="size-[22px] text-accent-green" />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[14.5px] font-semibold text-positive">Two-factor is on</p>
                            <p class="text-[12.5px] text-muted">A code from your app is required at sign-in.</p>
                        </div>
                    </div>

                    @if ($recoveryCodes)
                        <div class="mt-4 rounded-xl bg-surface-2 p-4">
                            <p class="text-[12.5px] font-semibold text-ink-2">Recovery codes</p>
                            <p class="mt-0.5 text-[12px] text-muted">
                                Each works once if you lose your phone. Store them somewhere safe.
                            </p>
                            <div class="tnum mt-2.5 grid grid-cols-2 gap-1.5">
                                @foreach ($recoveryCodes as $code)
                                    <span class="select-all rounded bg-surface px-2 py-1 text-center text-[12.5px] font-semibold text-ink">{{ $code }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <button type="button" wire:click="disableTwoFactor"
                            class="focusable mt-4 flex h-11 w-full items-center justify-center rounded-xl text-[14.5px] font-semibold text-negative hover:bg-tint-orange">
                        Turn off two-factor
                    </button>
                @else
                    <p class="text-[13.5px] leading-relaxed text-muted">
                        Adds a second step at sign-in using a code from your phone. Strongly recommended for
                        an account that can issue invoices and take payments.
                    </p>
                    <button type="button" wire:click="startTwoFactor"
                            class="focusable mt-4 flex h-11 w-full items-center justify-center rounded-xl bg-brand text-[14.5px] font-semibold text-white hover:opacity-90">
                        Set up two-factor
                    </button>
                @endif
            </x-ui.panel>

            <x-ui.panel title="Password">
                @if (session('passwordStatus'))
                    <div class="mb-4 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
                        {{ session('passwordStatus') }}
                    </div>
                @endif

                <div class="space-y-4">
                    <label class="block">
                        <span class="{{ $labelClass }}">Current password</span>
                        <input type="password" wire:model="currentPassword" autocomplete="current-password" class="{{ $inputClass }}">
                        @error('currentPassword') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>
                    <label class="block">
                        <span class="{{ $labelClass }}">New password</span>
                        <input type="password" wire:model="newPassword" autocomplete="new-password" class="{{ $inputClass }}">
                        @error('newPassword') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>
                    <label class="block">
                        <span class="{{ $labelClass }}">Confirm new password</span>
                        <input type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" class="{{ $inputClass }}">
                    </label>
                </div>

                <button type="button" wire:click="changePassword"
                        class="focusable mt-4 flex h-11 w-full items-center justify-center rounded-xl bg-surface-2 text-[14.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                    Change Password
                </button>
            </x-ui.panel>
        </div>

        {{-- Billing --}}
        @if ($company)
            <x-ui.panel title="Billing" action="Manage" :action-href="route('settings.billing')">
                <div class="flex items-center gap-3">
                    <span class="flex size-[42px] shrink-0 items-center justify-center rounded-xl bg-tint-blue">
                        <x-icon name="credit-card" class="size-[20px] text-brand" />
                    </span>
                    <div>
                        <p class="text-[14.5px] font-bold text-ink">{{ ucfirst($company->plan) }} plan</p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            @if ($company->plan_renews_at)
                                Renews {{ $company->plan_renews_at->format('d M Y') }}
                            @else
                                Pay with MTN Mobile Money or Orange Money.
                            @endif
                        </p>
                    </div>
                </div>
            </x-ui.panel>
        @endif

        {{-- Team --}}
        <x-ui.panel title="Team" body-class="-mx-1.5">
            @foreach ($team as $member)
                @php
                    $accent = Accent::forKey((string) $member->id);
                    $roleId = $member->companies->first()?->pivot?->role_id;
                @endphp
                <div wire:key="member-{{ $member->id }}"
                     class="flex items-center gap-3 rounded-lg px-1.5 py-2.5 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                    <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }} text-[12.5px] font-bold {{ Accent::text($accent) }}">
                        {{ $member->initials() }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[14.5px] font-semibold text-ink">{{ $member->name }}</span>
                        <span class="block truncate text-[12.5px] text-muted">{{ $member->email }}</span>
                    </span>
                    <span class="shrink-0 rounded-full bg-tint-blue px-2.5 py-1 text-[11.5px] font-semibold text-brand">
                        {{ $roles[$roleId]->name ?? 'Member' }}
                    </span>
                </div>
            @endforeach

            <p class="mt-3 px-1.5 text-[12.5px] text-muted">
                Invitations and role changes arrive with the full user-management module.
            </p>
        </x-ui.panel>

        {{-- Devices --}}
        <x-ui.panel title="Devices" body-class="-mx-1.5">
            @if (session('deviceStatus'))
                <div class="mx-1.5 mb-3 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
                    {{ session('deviceStatus') }}
                </div>
            @endif

            @forelse ($devices as $device)
                <div wire:key="device-{{ $device->id }}"
                     class="flex items-center gap-3 rounded-lg px-1.5 py-2.5 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                    <span class="flex size-[38px] shrink-0 items-center justify-center rounded-xl {{ $device->isRevoked() ? 'bg-surface-2' : 'bg-tint-green' }}">
                        <x-icon :name="$device->isRevoked() ? 'offline' : 'sync'"
                                class="size-[18px] {{ $device->isRevoked() ? 'text-faint' : 'text-accent-green' }}" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[14.5px] font-semibold text-ink">{{ $device->name }}</span>
                        <span class="block truncate text-[12.5px] text-muted">
                            {{ $device->user?->firstName() }}
                            @if ($device->last_synced_at) · synced {{ $device->last_synced_at->diffForHumans() }} @endif
                            @if ($device->pending_count > 0) · {{ $device->pending_count }} pending @endif
                        </span>
                    </span>
                    @unless ($device->isRevoked())
                        <button type="button" wire:click="revokeDevice('{{ $device->id }}')"
                                class="focusable shrink-0 text-[13px] font-semibold text-negative hover:underline">
                            Revoke
                        </button>
                    @endunless
                </div>
            @empty
                <div class="px-1.5 py-8 text-center">
                    <p class="text-[14px] font-semibold text-ink">No devices registered</p>
                    <p class="mt-1 text-[13px] text-muted">
                        Installing OPES360 on a phone registers it here, where it can be revoked if lost.
                    </p>
                </div>
            @endforelse
        </x-ui.panel>
    </div>
</div>
