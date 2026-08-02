@php
    $inputClass = 'mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'block text-[13.5px] font-semibold text-ink-2';
@endphp

<div class="mx-auto w-full max-w-[400px]">
    <div class="text-center">
        <div class="text-[30px] font-bold leading-none tracking-[-0.02em]">
            <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
        </div>
        <p class="mt-2 text-[14px] text-muted">{{ config('opes.brand.tagline') }}</p>
    </div>

    @if ($company === null)
        {{-- Spent, unknown or out of date. All three read the same to whoever
             is holding the link, and saying which would tell somebody guessing
             tokens whether they had found a real one. --}}
        <div class="card mt-7 p-6 text-center">
            <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">This invitation is no longer valid</h1>
            <p class="mx-auto mt-2 max-w-[300px] text-[14px] leading-relaxed text-muted">
                It may have already been used, been withdrawn, or simply run out — invitations last
                {{ \App\Services\TeamInvitations::VALID_FOR_DAYS }} days. Ask whoever invited you to send another.
            </p>
            <a href="{{ route('login') }}"
               class="tap focusable mt-6 inline-flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-5 text-[15px] font-semibold text-ink hover:bg-surface-2">
                Go to sign in
            </a>
        </div>
    @else
        <div class="card mt-7 p-6">
            <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">You have been invited</h1>
            <p class="mt-2 text-[14px] leading-relaxed text-muted">
                to work in <span class="font-semibold text-ink">{{ $company->name }}</span>
                @if ($company->city) in {{ $company->city }} @endif
                on {{ config('opes.brand.name') }}.
            </p>

            @error('token')
                <div class="mt-5 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                    {{ $message }}
                </div>
            @enderror

            @if ($needsPassword)
                <p class="mt-4 text-[13.5px] leading-relaxed text-muted">
                    Choose a name and a password and you are in. Your account is yours — if you ever work for another
                    business here, the same sign-in covers both.
                </p>

                <div class="mt-6 space-y-4">
                    <div>
                        <label class="{{ $labelClass }}" for="invite-name">Your name</label>
                        <input id="invite-name" type="text" wire:model="name" autocomplete="name"
                               class="{{ $inputClass }}" placeholder="Marie Ngo">
                        @error('name') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}" for="invite-email">Email</label>
                        <input id="invite-email" type="email" value="{{ $invitee?->email }}" disabled
                               class="{{ $inputClass }} bg-surface-2 text-muted">
                        <p class="mt-1.5 text-[12.5px] text-faint">This is the address the invitation was sent to.</p>
                    </div>

                    <div>
                        <label class="{{ $labelClass }}" for="invite-password">Choose a password</label>
                        <input id="invite-password" type="password" wire:model="password" autocomplete="new-password"
                               class="{{ $inputClass }}" placeholder="••••••••">
                        @error('password') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="{{ $labelClass }}" for="invite-password-confirm">Type it again</label>
                        <input id="invite-password-confirm" type="password" wire:model="passwordConfirmation"
                               autocomplete="new-password" class="{{ $inputClass }}" placeholder="••••••••">
                    </div>
                </div>
            @else
                <p class="mt-4 text-[13.5px] leading-relaxed text-muted">
                    You already have an account with <span class="font-semibold text-ink">{{ $invitee?->email }}</span>.
                    Accepting adds {{ $company->name }} to it — your password does not change, and you can switch
                    between businesses from the menu.
                </p>
            @endif

            <button type="button" wire:click="accept" wire:loading.attr="disabled"
                    class="tap focusable mt-6 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                {{ $needsPassword ? 'Create my account and join' : 'Accept the invitation' }}
            </button>

            <p class="mt-4 text-center text-[12.5px] leading-relaxed text-faint">
                Not expecting this? Close the page — nothing has happened yet.
            </p>
        </div>
    @endif
</div>
