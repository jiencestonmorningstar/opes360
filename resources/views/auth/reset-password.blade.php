<x-layouts.guest title="Choose a new password">
    <div class="w-full max-w-[400px]">
        <div class="text-center">
            <div class="text-[30px] font-bold leading-none tracking-[-0.02em]">
                <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
            </div>
        </div>

        <div class="card mt-7 p-6">
            <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">Choose a new password</h1>

            @if ($errors->any())
                <div class="mt-5 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <label class="block">
                    <span class="mb-1.5 block text-[13.5px] font-semibold text-ink-2">Email</span>
                    <input name="email" type="email" required value="{{ old('email', request('email')) }}"
                           class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-[13.5px] font-semibold text-ink-2">New password</span>
                    <input name="password" type="password" required autocomplete="new-password"
                           class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                </label>
                <label class="block">
                    <span class="mb-1.5 block text-[13.5px] font-semibold text-ink-2">Confirm new password</span>
                    <input name="password_confirmation" type="password" required autocomplete="new-password"
                           class="h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                </label>

                <button type="submit"
                        class="tap focusable flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
                    Update password
                </button>
            </form>
        </div>
    </div>
</x-layouts.guest>
