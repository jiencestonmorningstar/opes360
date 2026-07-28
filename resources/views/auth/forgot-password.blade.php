<x-layouts.guest title="Reset your password">
    <div class="w-full max-w-[400px]">
        <div class="text-center">
            <div class="text-[30px] font-bold leading-none tracking-[-0.02em]">
                <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
            </div>
        </div>

        <div class="card mt-7 p-6">
            <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">Reset your password</h1>
            <p class="mt-1 text-[14px] text-muted">We'll email you a link to choose a new one.</p>

            @if (session('status'))
                <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">
                    {{ session('status') }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
                @csrf
                <label class="block">
                    <span class="mb-1.5 block text-[13.5px] font-semibold text-ink-2">Email</span>
                    <input id="email" name="email" type="email" required autofocus value="{{ old('email') }}"
                           class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('email') <p class="mt-1 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                </label>

                <button type="submit"
                        class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[15px] font-semibold text-white hover:opacity-90">
                    Send reset link
                </button>
            </form>
        </div>

        <p class="mt-6 text-center text-[13.5px] text-muted">
            <a href="{{ route('login') }}" class="font-semibold text-brand hover:underline">Back to sign in</a>
        </p>
    </div>
</x-layouts.guest>
