@php
    $user = auth()->user();
    $currentCompany = app(\App\Support\CurrentCompany::class)->get();
@endphp

<header class="sticky top-0 z-20 bg-surface/95 backdrop-blur-sm dark:bg-canvas/95 lg:bg-transparent lg:backdrop-blur-none lg:dark:bg-transparent">
    <div class="flex items-start gap-3 px-5 pt-5 pb-4 lg:h-24 lg:items-center lg:justify-end lg:px-6 lg:pt-0 lg:pb-0">

        {{-- Mobile: the brand lives in the header. Desktop: it lives in the sidebar. --}}
        <button type="button" @click="drawer = true"
                class="tap focusable -ml-1 mt-0.5 flex items-center justify-center rounded-lg text-ink lg:hidden"
                aria-label="Open navigation">
            <x-icon name="menu" class="size-[26px]" />
        </button>

        <a href="{{ route('dashboard') }}" class="focusable min-w-0 lg:hidden">
            <div class="text-[26px] font-bold leading-none tracking-[-0.02em]">
                <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                    class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
            </div>
            <div class="mt-1.5 text-[13px] leading-none text-muted">{{ config('opes.brand.tagline') }}</div>
        </a>

        <div class="flex-1 lg:hidden"></div>

        {{-- Notifications --}}
        <button type="button"
                class="tap focusable relative flex items-center justify-center rounded-lg text-ink"
                aria-label="Notifications, 1 unread">
            <x-icon name="bell" class="size-[25px]" />
            <span class="absolute right-1.5 top-1.5 size-[9px] rounded-full bg-brand ring-2 ring-canvas lg:ring-canvas"></span>
        </button>

        {{-- Account --}}
        <div class="relative" x-data="{ menu: false }" @keydown.escape.window="menu = false">
            <button type="button" @click="menu = ! menu"
                    class="focusable flex items-center gap-3 rounded-xl lg:gap-3 lg:pl-1"
                    :aria-expanded="menu.toString()" aria-haspopup="menu">
                @if ($user?->avatarUrl())
                    <img src="{{ $user->avatarUrl() }}" alt=""
                         class="size-[42px] rounded-xl object-cover lg:size-[46px] lg:rounded-full">
                @else
                    <span class="flex size-[42px] items-center justify-center rounded-xl bg-tint-blue text-[15px] font-semibold text-accent-blue lg:size-[46px] lg:rounded-full">
                        {{ $user?->initials() ?? 'U' }}
                    </span>
                @endif

                <span class="hidden text-left lg:block">
                    <span class="block text-[15.5px] font-semibold leading-tight text-ink">{{ $user?->name ?? 'Guest' }}</span>
                    <span class="block text-[13px] leading-tight text-muted">{{ $jobTitle ?? 'Business Owner' }}</span>
                </span>

                <x-icon name="chevron-down" class="hidden size-[19px] text-muted lg:block" />
            </button>

            <div x-cloak x-show="menu" @click.outside="menu = false"
                 x-transition.origin.top.right
                 class="card absolute right-0 top-[calc(100%+0.5rem)] z-30 w-60 overflow-hidden p-1.5 shadow-[var(--shadow-raised)]"
                 role="menu">
                <div class="border-b border-border px-3 py-2.5 lg:hidden">
                    <p class="truncate text-[14.5px] font-semibold text-ink">{{ $user?->name ?? 'Guest' }}</p>
                    <p class="truncate text-[12.5px] text-muted">{{ $user?->email }}</p>
                </div>

                {{-- Which business you are acting as. Multi-company owners need
                     this visible at all times, not buried in settings. --}}
                @if ($currentCompany)
                    <a href="{{ route('businesses') }}" role="menuitem"
                       class="flex w-full items-center gap-3 rounded-lg border-b border-border px-3 py-2.5 hover:bg-surface-2">
                        <x-icon name="briefcase" class="size-5 shrink-0 text-muted" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-semibold text-ink">{{ $currentCompany->name }}</span>
                            <span class="block text-[11.5px] text-muted">Switch business</span>
                        </span>
                        <x-icon name="chevron-right" class="size-4 shrink-0 text-faint" stroke-width="2.2" />
                    </a>
                @endif

                {{--
                    The theme switch lives here rather than in the header so the top
                    bar matches the design exactly, while dark mode stays reachable.
                --}}
                <button type="button" @click="toggleTheme()" role="menuitem"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-[14.5px] font-medium text-ink-2 hover:bg-surface-2">
                    <x-icon name="sun" class="size-5 text-muted dark:hidden" />
                    <x-icon name="moon" class="hidden size-5 text-muted dark:block" />
                    <span class="dark:hidden">Dark mode</span>
                    <span class="hidden dark:inline">Light mode</span>
                </button>

                <a href="{{ route('settings') }}" role="menuitem"
                   class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-[14.5px] font-medium text-ink-2 hover:bg-surface-2">
                    <x-icon name="cog" class="size-5 text-muted" />
                    Settings
                </a>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" role="menuitem"
                            class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-[14.5px] font-medium text-ink-2 hover:bg-surface-2">
                        <x-icon name="logout" class="size-5 text-muted" />
                        Sign out
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
