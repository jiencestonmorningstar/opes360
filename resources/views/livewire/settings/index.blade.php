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
