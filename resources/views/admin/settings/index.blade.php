@php
    $enrolling = ! $admin->hasTwoFactorEnabled() && $admin->twoFactorSecret() !== null;
@endphp

<x-layouts.admin title="Settings" active="settings">
    <div class="px-5 py-8 lg:px-8">
        <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">Settings</h1>
        <p class="mt-1 text-[14px] text-muted">{{ $admin->name }} · {{ $admin->email }}</p>

        <div class="mt-5 max-w-lg card p-5">
            <p class="text-[15px] font-bold text-ink">Two-factor authentication</p>

            @if (session('status'))
                <div class="mt-3 rounded-xl bg-tint-green px-4 py-2.5 text-[13px] font-semibold text-positive">
                    {{ session('status') }}
                </div>
            @endif

            @if ($enrolling)
                <p class="mt-3 text-[13.5px] leading-relaxed text-muted">
                    Scan this with Google Authenticator, 1Password or any TOTP app, then enter the
                    6-digit code it shows to finish.
                </p>

                <div class="mt-4 flex flex-col items-center rounded-xl border border-border p-4">
                    <img src="{{ route('admin.two-factor.qr') }}" alt="Two-factor setup QR"
                         class="size-[168px] rounded-lg bg-white p-2">
                    <p class="mt-3 text-[12px] text-muted">Can't scan? Enter this key manually:</p>
                    <p class="tnum mt-1 select-all break-all text-center text-[13px] font-semibold text-ink">
                        {{ $admin->twoFactorSecret() }}
                    </p>
                </div>

                <form method="POST" action="{{ route('admin.two-factor.confirm') }}" class="mt-4">
                    @csrf
                    <label class="block">
                        <span class="text-[13.5px] font-semibold text-ink-2">6-digit code</span>
                        <input type="text" inputmode="numeric" name="code" placeholder="000000" required autofocus
                               class="tnum mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-center text-[17px] tracking-[0.3em] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                        @error('code') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    </label>

                    <div class="mt-4 flex gap-3">
                        <button type="submit" formaction="{{ route('admin.two-factor.cancel') }}"
                                class="focusable h-11 flex-1 rounded-xl bg-surface-2 text-[14.5px] font-semibold text-ink-2 hover:bg-tint-blue hover:text-brand">
                            Cancel
                        </button>
                        <button type="submit"
                                class="focusable h-11 flex-[1.4] rounded-xl bg-ink text-[14.5px] font-semibold text-white hover:opacity-90">
                            Turn on
                        </button>
                    </div>
                </form>
            @elseif ($admin->hasTwoFactorEnabled())
                <div class="mt-3 flex items-center gap-3">
                    <span class="flex size-[42px] shrink-0 items-center justify-center rounded-xl bg-tint-green">
                        <x-icon name="check-circle" class="size-[22px] text-positive" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[14.5px] font-semibold text-positive">Two-factor is on</p>
                        <p class="text-[12.5px] text-muted">A code from your app is required at sign-in.</p>
                    </div>
                </div>

                @if ($codes = $admin->recoveryCodes())
                    <div class="mt-4 rounded-xl bg-surface-2 p-4">
                        <p class="text-[12.5px] font-semibold text-ink-2">Recovery codes</p>
                        <p class="mt-0.5 text-[12px] text-muted">
                            Each works once if you lose your phone. Store them somewhere safe.
                        </p>
                        <div class="tnum mt-2.5 grid grid-cols-2 gap-1.5">
                            @foreach ($codes as $code)
                                <span class="select-all rounded bg-surface px-2 py-1 text-center text-[12.5px] font-semibold text-ink">{{ $code }}</span>
                            @endforeach
                        </div>
                    </div>
                @endif

                <form method="POST" action="{{ route('admin.two-factor.disable') }}" class="mt-4"
                      onsubmit="return confirm('Turn off two-factor authentication?')">
                    @csrf
                    <button type="submit" class="focusable flex h-11 w-full items-center justify-center rounded-xl text-[14.5px] font-semibold text-warning hover:bg-tint-orange">
                        Turn off two-factor
                    </button>
                </form>
            @else
                <p class="mt-3 text-[13.5px] leading-relaxed text-muted">
                    Adds a second step at sign-in using a code from your phone. Strongly recommended for
                    an account with full read access to every business.
                </p>
                <form method="POST" action="{{ route('admin.two-factor.start') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="focusable flex h-11 w-full items-center justify-center rounded-xl bg-ink text-[14.5px] font-semibold text-white hover:opacity-90">
                        Set up two-factor
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-layouts.admin>
