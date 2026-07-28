{{-- Shared sub-navigation for everything under Business. --}}
<div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0">
    @foreach ([
        ['label' => 'Profile', 'route' => 'business', 'icon' => 'briefcase'],
        ['label' => 'Stationery', 'route' => 'stationery', 'icon' => 'printer'],
        ['label' => 'Artisans', 'route' => 'artisans', 'icon' => 'users'],
        ['label' => 'Businesses', 'route' => 'businesses', 'icon' => 'cube'],
    ] as $tab)
        @php $isActive = request()->routeIs($tab['route']); @endphp
        <a href="{{ route($tab['route']) }}"
           class="focusable flex h-10 shrink-0 items-center gap-2 rounded-full px-4 text-[13.5px] font-semibold transition-colors
                  {{ $isActive ? 'bg-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
            <x-icon :name="$tab['icon']" class="size-[16px]" />
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>
