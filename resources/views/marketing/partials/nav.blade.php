{{--
    Shared marketing nav. A plain header, not the authenticated app shell —
    this renders for visitors who have no session and no company yet.
--}}
<header class="border-b border-border">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-5">
        <a href="{{ route('dashboard') }}" class="text-[22px] font-bold leading-none tracking-[-0.02em]">
            <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
        </a>

        <nav class="hidden items-center gap-7 text-[14.5px] font-medium text-ink-2 sm:flex">
            <a href="{{ route('marketing.features') }}" class="hover:text-brand">Features</a>
            <a href="{{ route('marketing.pricing') }}" class="hover:text-brand">Pricing</a>
            <a href="{{ route('marketing.about') }}" class="hover:text-brand">About</a>
            <a href="{{ route('marketing.contact') }}" class="hover:text-brand">Contact</a>
        </nav>

        <div class="flex items-center gap-2.5">
            <a href="{{ route('login') }}"
               class="tap focusable hidden h-10 items-center rounded-xl px-4 text-[14px] font-semibold text-ink-2 hover:text-ink sm:inline-flex">
                Sign in
            </a>
            <a href="{{ route('register') }}"
               class="tap focusable inline-flex h-10 items-center rounded-xl bg-brand px-4 text-[14px] font-semibold text-white transition-opacity hover:opacity-90">
                Create your business
            </a>
        </div>
    </div>
</header>
