@php $admin = auth('admin')->user(); @endphp

<header class="sticky top-0 z-20 bg-surface/95 backdrop-blur-sm dark:bg-canvas/95 lg:bg-transparent lg:backdrop-blur-none lg:dark:bg-transparent">
    <div class="flex items-start gap-3 px-5 pt-5 pb-4 lg:h-24 lg:items-center lg:justify-end lg:px-6 lg:pt-0 lg:pb-0">

        <button type="button" @click="drawer = true"
                class="tap focusable -ml-1 mt-0.5 flex items-center justify-center rounded-lg text-ink lg:hidden"
                aria-label="Open navigation">
            <x-icon name="menu" class="size-[26px]" />
        </button>

        <a href="{{ route('admin.dashboard') }}" class="focusable min-w-0 lg:hidden">
            <div class="text-[22px] font-bold leading-none tracking-[-0.02em]">
                <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                    class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
            </div>
            <div class="mt-1.5 text-[12px] font-bold uppercase tracking-wide text-ink-2">Platform Admin</div>
        </a>

        <div class="flex-1 lg:hidden"></div>

        <div class="relative" x-data="{ menu: false }" @keydown.escape.window="menu = false">
            <button type="button" @click="menu = ! menu"
                    class="focusable flex items-center gap-3 rounded-xl lg:gap-3 lg:pl-1"
                    :aria-expanded="menu.toString()" aria-haspopup="menu">
                <span class="flex size-[42px] items-center justify-center rounded-xl bg-ink text-[15px] font-semibold text-white lg:size-[46px] lg:rounded-full">
                    {{ $admin ? strtoupper(substr($admin->name, 0, 1)) : 'A' }}
                </span>

                <span class="hidden text-left lg:block">
                    <span class="block text-[15.5px] font-semibold leading-tight text-ink">{{ $admin?->name ?? 'Admin' }}</span>
                    <span class="block text-[13px] leading-tight text-muted">{{ $admin?->email }}</span>
                </span>

                <x-icon name="chevron-down" class="hidden size-[19px] text-muted lg:block" />
            </button>

            <div x-cloak x-show="menu" @click.outside="menu = false"
                 x-transition.origin.top.right
                 class="card absolute right-0 top-[calc(100%+0.5rem)] z-30 w-60 overflow-hidden p-1.5 shadow-[var(--shadow-raised)]"
                 role="menu">
                <div class="border-b border-border px-3 py-2.5 lg:hidden">
                    <p class="truncate text-[14.5px] font-semibold text-ink">{{ $admin?->name ?? 'Admin' }}</p>
                    <p class="truncate text-[12.5px] text-muted">{{ $admin?->email }}</p>
                </div>

                <button type="button" @click="toggleTheme()" role="menuitem"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-[14.5px] font-medium text-ink-2 hover:bg-surface-2">
                    <x-icon name="sun" class="size-5 text-muted dark:hidden" />
                    <x-icon name="moon" class="hidden size-5 text-muted dark:block" />
                    <span class="dark:hidden">Dark mode</span>
                    <span class="hidden dark:inline">Light mode</span>
                </button>

                <a href="{{ route('admin.settings') }}" role="menuitem"
                   class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-[14.5px] font-medium text-ink-2 hover:bg-surface-2">
                    <x-icon name="cog" class="size-5 text-muted" />
                    Settings
                    @if ($admin && ! $admin->hasTwoFactorEnabled())
                        <span class="ml-auto size-2 rounded-full bg-fill-warning" title="Two-factor is off"></span>
                    @endif
                </a>

                <a href="{{ route('login') }}" role="menuitem"
                   class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-[14.5px] font-medium text-ink-2 hover:bg-surface-2">
                    <x-icon name="briefcase" class="size-5 text-muted" />
                    Business sign-in
                </a>

                <form method="POST" action="{{ route('admin.logout') }}">
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
