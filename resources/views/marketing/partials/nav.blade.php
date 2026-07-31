{{--
    Shared marketing nav. A plain header, not the authenticated app shell —
    this renders for visitors who have no session and no company yet.
--}}
<header class="sticky top-0 z-30 border-b border-border bg-canvas/90 backdrop-blur-sm" x-data="{ open: false }" @keydown.escape.window="open = false">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-5">
        <a href="{{ route('dashboard') }}" class="text-[22px] font-bold leading-none tracking-[-0.02em]">
            <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
        </a>

        <nav class="hidden items-center gap-7 text-[14.5px] font-medium text-ink-2 lg:flex">
            <a href="{{ route('marketing.features') }}" class="hover:text-brand">Features</a>
            <a href="{{ route('marketing.pricing') }}" class="hover:text-brand">Pricing</a>
            <a href="{{ route('marketing.blog') }}" class="hover:text-brand">Blog</a>
            <a href="{{ route('marketing.about') }}" class="hover:text-brand">About</a>
            <a href="{{ route('marketing.contact') }}" class="hover:text-brand">Contact</a>
        </nav>

        <div class="hidden items-center gap-2.5 lg:flex">
            <a href="{{ route('login') }}"
               class="tap focusable inline-flex h-10 items-center rounded-xl px-4 text-[14px] font-semibold text-ink-2 hover:text-ink">
                Sign in
            </a>
            <a href="{{ route('demo.request') }}"
               class="tap focusable inline-flex h-10 items-center rounded-xl bg-brand px-4 text-[14px] font-semibold text-white transition-opacity hover:opacity-90">
                Try a demo
            </a>
        </div>

        {{-- Mobile: everything above collapses into this button. Without it
             there was no way to reach Blog, About or Contact under lg. --}}
        <button type="button" @click="open = ! open" :aria-expanded="open.toString()" aria-haspopup="menu"
                aria-label="Open menu"
                class="tap focusable flex size-10 items-center justify-center rounded-xl text-ink lg:hidden">
            <x-icon name="menu" x-show="!open" class="size-[24px]" />
            <svg x-show="open" x-cloak viewBox="0 0 24 24" fill="none" class="size-[22px]" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M6 6l12 12M18 6L6 18" />
            </svg>
        </button>
    </div>

    <div x-cloak x-show="open" x-transition.origin.top @click.outside="open = false" class="border-t border-border bg-canvas lg:hidden">
        <nav class="mx-auto flex max-w-6xl flex-col gap-1 px-5 py-4 text-[15px] font-medium text-ink-2">
            <a href="{{ route('marketing.features') }}" class="rounded-lg px-2 py-2.5 hover:bg-surface-2 hover:text-ink">Features</a>
            <a href="{{ route('marketing.pricing') }}" class="rounded-lg px-2 py-2.5 hover:bg-surface-2 hover:text-ink">Pricing</a>
            <a href="{{ route('marketing.blog') }}" class="rounded-lg px-2 py-2.5 hover:bg-surface-2 hover:text-ink">Blog</a>
            <a href="{{ route('marketing.about') }}" class="rounded-lg px-2 py-2.5 hover:bg-surface-2 hover:text-ink">About</a>
            <a href="{{ route('marketing.contact') }}" class="rounded-lg px-2 py-2.5 hover:bg-surface-2 hover:text-ink">Contact</a>

            <div class="mt-3 flex flex-col gap-2.5 border-t border-border pt-4">
                <a href="{{ route('login') }}"
                   class="tap focusable flex h-11 items-center justify-center rounded-xl border border-border text-[14.5px] font-semibold text-ink">
                    Sign in
                </a>
                <a href="{{ route('demo.request') }}"
                   class="tap focusable flex h-11 items-center justify-center rounded-xl bg-brand text-[14.5px] font-semibold text-white">
                    Try a demo
                </a>
            </div>
        </nav>
    </div>
</header>
