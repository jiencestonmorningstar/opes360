<x-layouts.guest title="Two-factor verification">
    <div class="w-full max-w-[400px]">
        <div class="text-center">
            <div class="text-[30px] font-bold leading-none tracking-[-0.02em]">
                <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
            </div>
        </div>

        <div class="card mt-7 p-6">
            <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">Two-factor verification</h1>
            <p class="mt-1 text-[14px] text-muted">
                Enter the 6-digit code from your authenticator app, or one of your recovery codes.
            </p>

            @if ($errors->any())
                <div class="mt-5 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('two-factor.verify') }}" class="mt-6 space-y-4">
                @csrf
                <label class="block">
                    <span class="mb-1.5 block text-[13.5px] font-semibold text-ink-2">Code</span>
                    <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                           required autofocus placeholder="000000"
                           class="tnum h-12 w-full rounded-xl border border-border bg-surface px-4 text-center text-[18px] tracking-[0.3em] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                </label>

                <button type="submit"
                        class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white hover:opacity-90">
                    Verify
                </button>
            </form>
        </div>

        <p class="mt-6 text-center text-[13.5px] text-muted">
            <a href="{{ route('login') }}" class="font-semibold text-brand hover:underline">Back to sign in</a>
        </p>
    </div>
</x-layouts.guest>
