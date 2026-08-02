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
                        class="tap focusable shrink-0 rounded-xl bg-fill-brand px-4 py-2.5 text-[13.5px] font-semibold text-white transition-opacity hover:opacity-90">
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
                    class="focusable mt-4 flex h-11 w-full items-center justify-center rounded-xl bg-fill-brand text-[14.5px] font-semibold text-white hover:opacity-90">
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
                                class="focusable h-11 flex-[1.4] rounded-xl bg-fill-brand text-[14.5px] font-semibold text-white hover:opacity-90">
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
                            class="focusable mt-4 flex h-11 w-full items-center justify-center rounded-xl bg-fill-brand text-[14.5px] font-semibold text-white hover:opacity-90">
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
            @can('users.invite')
                <x-slot:actions>
                    <button type="button" wire:click="startInviting"
                            class="focusable text-[14px] font-semibold text-brand hover:opacity-80">Invite</button>
                </x-slot:actions>
            @endcan

            @if (session('teamStatus'))
                <div class="mx-1.5 mb-3 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
                    {{ session('teamStatus') }}
                </div>
            @endif
            @if (session('teamError'))
                <div class="mx-1.5 mb-3 rounded-xl bg-tint-red px-4 py-2.5 text-[13px] font-semibold text-negative">
                    {{ session('teamError') }}
                </div>
            @endif

            @if ($inviting)
                <div class="mx-1.5 mb-4 rounded-xl border border-brand bg-surface p-4">
                    <p class="text-[13.5px] leading-relaxed text-muted">
                        They get an email with a link. Nothing changes for them until they open it, and the link stops
                        working after {{ \App\Services\TeamInvitations::VALID_FOR_DAYS }} days.
                    </p>

                    <div class="mt-3.5 space-y-3">
                        <div>
                            <label for="invite-email" class="mb-1.5 block text-[13px] font-semibold text-ink-2">Email</label>
                            <input id="invite-email" type="email" wire:model="inviteEmail" autocomplete="off"
                                   placeholder="marie@example.com"
                                   class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                            @error('inviteEmail') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="invite-role" class="mb-1.5 block text-[13px] font-semibold text-ink-2">Role</label>
                                <select id="invite-role" wire:model="inviteRole"
                                        class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                    @foreach ($assignableRoles as $role)
                                        <option value="{{ $role->id }}">{{ $role->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="invite-title" class="mb-1.5 block text-[13px] font-semibold text-ink-2">
                                    Job title <span class="font-normal text-faint">(optional)</span>
                                </label>
                                <input id="invite-title" type="text" wire:model="inviteJobTitle" maxlength="80"
                                       class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-col gap-3 sm:flex-row-reverse">
                        <button type="button" wire:click="sendInvite" wire:loading.attr="disabled"
                                class="tap focusable flex h-11 items-center justify-center rounded-xl bg-fill-brand px-5 text-[14.5px] font-semibold text-white hover:opacity-90">
                            Send the invitation
                        </button>
                        <button type="button" wire:click="$set('inviting', false)"
                                class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif

            @foreach ($team as $member)
                @php
                    $accent = Accent::forKey((string) $member->id);
                    $pivot = $member->companies->first()?->pivot;
                    $roleId = $pivot?->role_id;
                    $pending = $pivot?->status === 'invited';
                    $isOwner = $company?->owner_id === $member->id;
                    $isSelf = auth()->id() === $member->id;
                @endphp
                {{-- Two rows on a phone, one from `sm`. Side by side at 360px
                     the role select took its width first and the name column
                     collapsed to "S.." — a team list whose whole job is saying
                     who somebody is. --}}
                <div wire:key="member-{{ $member->id }}"
                     class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg px-1.5 py-2.5 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                    <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }} text-[12.5px] font-bold {{ Accent::text($accent) }}">
                        {{ $member->initials() }}
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[14.5px] font-semibold text-ink">
                            {{ $member->name }}@if ($isSelf) <span class="font-normal text-faint">(you)</span> @endif
                        </span>
                        <span class="block truncate text-[12.5px] text-muted">{{ $member->email }}</span>
                    </span>

                    @if ($pending)
                        <span class="shrink-0 rounded-full bg-tint-amber px-2.5 py-1 text-[11.5px] font-semibold text-warning">
                            Invited
                        </span>
                    @endif

                    <div class="flex w-full items-center justify-end gap-2 sm:w-auto">
                        {{-- The owner's role and your own are shown, never
                             offered: an administrator who demotes themselves has
                             locked the business out of its own settings. --}}
                        @if ($isOwner || $isSelf || ! auth()->user()->can('users.update-role'))
                            <span class="shrink-0 rounded-full bg-tint-blue px-2.5 py-1 text-[11.5px] font-semibold text-brand">
                                {{ $roles[$roleId]->name ?? 'Member' }}
                            </span>
                        @else
                            <label class="sr-only" for="role-{{ $member->id }}">{{ $member->name }}’s role</label>
                            <select id="role-{{ $member->id }}"
                                    wire:change="changeRole({{ $member->id }}, $event.target.value)"
                                    class="h-10 min-w-0 rounded-xl border border-border bg-surface px-2.5 text-[13px] font-semibold text-ink-2 focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                                @foreach ($assignableRoles as $role)
                                    <option value="{{ $role->id }}" @selected($role->id === $roleId)>{{ $role->name }}</option>
                                @endforeach
                            </select>
                        @endif

                        @if ($pending && auth()->user()->can('users.invite'))
                            <button type="button" wire:click="resendInvite({{ $member->id }})"
                                    class="focusable flex h-10 shrink-0 items-center rounded-xl px-2.5 text-[13px] font-semibold text-brand hover:bg-tint-blue">
                                Resend
                            </button>
                        @endif

                        {{-- Worded, not an icon: the icon set has no unambiguous
                             "remove", and the nearest one reads as a warning
                             rather than an action. --}}
                        @if (! $isOwner && ! $isSelf && auth()->user()->can('users.remove'))
                            <button type="button" wire:click="removeMember({{ $member->id }})"
                                    wire:confirm="Remove {{ $member->name }} from {{ $company?->name }}? They lose access immediately. Everything they created stays."
                                    class="focusable flex h-10 shrink-0 items-center rounded-xl px-2.5 text-[13px] font-semibold text-negative hover:bg-tint-red">
                                Remove
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach

            <p class="mt-3 px-1.5 text-[12.5px] leading-relaxed text-muted">
                Ownership is not assigned from here. Everything a member creates — invoices, receipts, journal entries —
                keeps their name on it after they leave.
            </p>
        </x-ui.panel>

        {{-- Modules. A hairdresser has no fixed asset register and a
             consultancy has no stock; every module a business will never open
             makes the navigation longer for nothing. --}}
        @can('business.update')
            <x-ui.panel title="Modules" body-class="-mx-1.5" class="lg:col-span-2">
                <p class="mx-1.5 text-[13px] leading-relaxed text-muted">
                    Switch off what this business does not do. Nothing is deleted — the screens go quiet and the data
                    waits, so turning something back on picks up exactly where it left off.
                </p>

                @if (session('moduleStatus'))
                    <div class="mx-1.5 mt-3 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
                        {{ session('moduleStatus') }}
                    </div>
                @endif

                <div class="mt-3 grid gap-x-6 sm:grid-cols-2">
                    @foreach ($modules as $key => $module)
                        @php
                            $on = in_array($key, $enabledModules, true);
                            $blockedBy = collect($module['requires'] ?? [])
                                ->reject(fn ($needed) => in_array($needed, $enabledModules, true));
                        @endphp

                        <div wire:key="module-{{ $key }}" class="flex items-start gap-3 border-t border-border px-1.5 py-3.5">
                            <span class="mt-0.5 flex size-[34px] shrink-0 items-center justify-center rounded-xl {{ $on ? 'bg-tint-blue' : 'bg-surface-2' }}">
                                <x-icon :name="$module['icon'] ?? 'cube'"
                                        class="size-[17px] {{ $on ? 'text-brand' : 'text-faint' }}" stroke-width="1.9" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-[14.5px] font-semibold {{ $on ? 'text-ink' : 'text-muted' }}">
                                    {{ $module['label'] }}
                                </span>
                                <span class="mt-0.5 block text-[12.5px] leading-relaxed text-muted">
                                    {{ $module['description'] }}
                                </span>
                                @if ($blockedBy->isNotEmpty())
                                    <span class="mt-1 block text-[12px] font-medium text-warning">
                                        Needs {{ $blockedBy->map(fn ($k) => \App\Support\Modules::label($k))->join(' and ') }}.
                                    </span>
                                @endif
                            </span>

                            {{-- A real checkbox rather than a styled div: it is
                                 what a screen reader and a keyboard already
                                 know how to operate. --}}
                            <label class="tap focusable relative mt-0.5 flex shrink-0 cursor-pointer items-center">
                                <input type="checkbox" class="peer sr-only"
                                       wire:click="toggleModule('{{ $key }}')"
                                       @checked($on)
                                       aria-label="{{ $on ? 'Switch off' : 'Switch on' }} {{ $module['label'] }}">
                                <span class="h-[26px] w-[46px] rounded-full bg-border-strong transition-colors peer-checked:bg-fill-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40"></span>
                                <span class="pointer-events-none absolute left-[3px] size-[20px] rounded-full bg-white shadow transition-transform peer-checked:translate-x-[20px]"></span>
                            </label>
                        </div>
                    @endforeach
                </div>
            </x-ui.panel>
        @endcan

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
                        {{-- Destructive, and it was a 20px-tall text button: the
                             padding is part of the target rather than dead space
                             around it. --}}
                        <button type="button" wire:click="revokeDevice('{{ $device->id }}')"
                                class="focusable -mr-1.5 shrink-0 rounded-lg px-2.5 py-2 text-[13px] font-semibold text-negative hover:bg-tint-red hover:underline">
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
