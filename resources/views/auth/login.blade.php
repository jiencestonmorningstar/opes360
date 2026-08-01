<x-layouts.public title="Sign in" width="max-w-[400px]">
    <div class="text-center">
        <div class="text-[30px] font-bold leading-none tracking-[-0.02em]">
            <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
        </div>
        <p class="mt-2 text-[14px] text-muted">{{ config('opes.brand.tagline') }}</p>
    </div>

    <div class="card mt-7 p-6">
        <h1 class="text-[20px] font-bold tracking-[-0.02em] text-ink">Welcome back</h1>
        <p class="mt-1 text-[14px] text-muted">Sign in to continue to your business.</p>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mt-5 rounded-xl bg-tint-orange px-4 py-3 text-[13.5px] font-medium text-warning">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-[13.5px] font-semibold text-ink-2">Email</label>
                <input id="email" name="email" type="email" required autofocus autocomplete="email"
                       value="{{ old('email') }}"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                       placeholder="you@business.com">
            </div>

            <div>
                <label for="password" class="block text-[13.5px] font-semibold text-ink-2">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password"
                       class="mt-1.5 h-12 w-full rounded-xl border border-border bg-surface px-4 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"
                       placeholder="••••••••">
            </div>

            <div class="flex items-center justify-between gap-3 pt-1">
                <label class="flex items-center gap-2.5 text-[14px] text-ink-2">
                    <input type="checkbox" name="remember" value="1"
                           class="size-[18px] rounded border-border-strong text-brand focus:ring-brand/30">
                    Keep me signed in
                </label>
                <a href="{{ route('password.request') }}" class="text-[13.5px] font-semibold text-brand hover:underline">
                    Forgot password?
                </a>
            </div>

            <button type="submit"
                    class="tap focusable mt-2 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Sign in
            </button>
        </form>

        <p class="mt-5 text-center text-[13.5px] text-muted">
            New to {{ config('opes.brand.name') }}?
            <a href="{{ route('register') }}" class="font-semibold text-brand hover:underline">Create your business</a>
            · <a href="{{ route('demo.request') }}" class="font-semibold text-brand hover:underline">Try a demo</a>
        </p>
    </div>

    @if (config('opes.demo.enabled'))
        {{-- One-tap demo sign-ins. Plain POSTs of the seeded credentials through
             the normal login flow — same throttle, same session handling. --}}
        <div class="card mt-4 p-5">
            <div class="flex items-center gap-2">
                <span class="flex size-[30px] items-center justify-center rounded-lg bg-tint-purple">
                    <x-icon name="spark" class="size-[17px] text-accent-purple" stroke-width="1.9" />
                </span>
                <div>
                    <p class="text-[14.5px] font-semibold text-ink">Just looking around?</p>
                    <p class="text-[12.5px] text-muted">Sign in to the demo business with one tap.</p>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-1 gap-2.5 min-[400px]:grid-cols-2">
                @foreach (config('opes.demo.accounts') as $account)
                    <form method="POST" action="{{ route('login') }}">
                        @csrf
                        <input type="hidden" name="email" value="{{ $account['email'] }}">
                        <input type="hidden" name="password" value="{{ config('opes.demo.password') }}">
                        <button type="submit"
                                class="tap focusable flex h-auto w-full flex-col items-start gap-0.5 rounded-xl border border-border bg-surface px-4 py-3 text-left transition-colors hover:border-brand/40 hover:bg-surface-2">
                            <span class="text-[14px] font-semibold text-ink">{{ $account['label'] }}</span>
                            <span class="text-[12px] leading-snug text-muted">{{ $account['detail'] }}</span>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    @endif

    <p class="mt-6 text-center text-[12.5px] text-muted">
        Built by <a href="{{ config('opes.brand.vendor_url') }}" class="font-medium text-brand hover:underline">{{ config('opes.brand.vendor') }}</a>
        · <a href="{{ route('admin.login') }}" class="hover:text-ink-2">Platform admin</a>
    </p>
</x-layouts.public>
