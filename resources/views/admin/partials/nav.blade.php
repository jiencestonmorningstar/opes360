{{-- Minimal admin chrome — this is a staff tool, not a customer-facing shell. --}}
<header class="border-b border-border">
    <div class="mx-auto flex h-16 max-w-6xl items-center justify-between px-5">
        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5">
            <div class="text-[19px] font-bold leading-none tracking-[-0.02em]">
                <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
            </div>
            <span class="rounded-full bg-tint-blue px-2.5 py-1 text-[11px] font-bold uppercase tracking-wide text-brand">Admin</span>
        </a>

        <nav class="hidden items-center gap-6 text-[14px] font-medium text-ink-2 sm:flex">
            <a href="{{ route('admin.dashboard') }}" class="hover:text-ink {{ request()->routeIs('admin.dashboard') ? 'text-ink' : '' }}">Dashboard</a>
            <a href="{{ route('admin.companies') }}" class="hover:text-ink {{ request()->routeIs('admin.companies*') ? 'text-ink' : '' }}">Companies</a>
        </nav>

        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit" class="focusable text-[13.5px] font-semibold text-muted hover:text-ink">Sign out</button>
        </form>
    </div>
</header>
