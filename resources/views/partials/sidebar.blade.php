{{--
    One element serves both roles: a fixed off-canvas drawer below `lg`, and a
    static collapsible rail from `lg` up. Keeping it as a single markup tree means
    the nav list has exactly one source of truth.
--}}
<aside x-cloak
       class="fixed inset-y-0 left-0 z-40 flex w-[280px] shrink-0 flex-col bg-surface transition-transform duration-200 ease-out
              lg:sticky lg:top-0 lg:z-auto lg:h-screen lg:translate-x-0 lg:bg-canvas lg:transition-[width]"
       :class="{
           '-translate-x-full': ! drawer,
           'translate-x-0': drawer,
           'lg:w-[280px]': ! collapsed,
           'lg:w-[88px]': collapsed,
       }"
       aria-label="Main navigation">

    {{-- Brand block --}}
    <div class="flex items-start gap-3 px-5 pt-5 pb-4" :class="collapsed && 'lg:justify-center lg:px-0'">
        <button type="button" @click="toggleSidebar()"
                class="tap focusable -ml-1 mt-0.5 flex items-center justify-center rounded-lg text-ink"
                aria-label="Toggle navigation">
            <x-icon name="menu" class="size-[26px]" />
        </button>

        <a href="{{ route('dashboard') }}" class="focusable min-w-0" :class="collapsed && 'lg:hidden'">
            <div class="text-[26px] font-bold leading-none tracking-[-0.02em]">
                <span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span
                    class="text-brand">{{ config('opes.brand.name_suffix') }}</span>
            </div>
            <div class="mt-1.5 text-[13px] leading-none text-muted">{{ config('opes.brand.tagline') }}</div>
        </a>
    </div>

    <div class="mx-5 border-t border-border" :class="collapsed && 'lg:mx-4'"></div>

    {{-- Navigation --}}
    <nav class="no-scrollbar flex-1 overflow-y-auto px-4 py-4">
        <ul class="space-y-1">
            @foreach (\App\Support\Navigation::items() as $item)
                @php
                    $isActive = $active === $item['key'];
                    $href = $item['route'] ? route($item['route']) : '#';
                @endphp
                <li>
                    <a href="{{ $href }}"
                       @if (! $item['route']) aria-disabled="true" @endif
                       @if ($isActive) aria-current="page" @endif
                       class="focusable group flex h-[52px] items-center gap-3.5 rounded-xl px-3.5 text-[15.5px] font-medium transition-colors
                              {{ $isActive
                                  ? 'bg-tint-blue text-brand'
                                  : 'text-ink-2 hover:bg-surface-2' }}"
                       :class="collapsed && 'lg:justify-center lg:px-0'"
                       title="{{ $item['label'] }}">
                        <x-icon :name="$item['icon']"
                                class="size-[23px] shrink-0 {{ $isActive ? 'text-brand' : 'text-muted group-hover:text-ink-2' }}" />
                        <span class="truncate" :class="collapsed && 'lg:hidden'">{{ $item['label'] }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    </nav>

    {{-- Collapse control (desktop only; the drawer closes by tapping the scrim) --}}
    <div class="hidden border-t border-border px-4 py-4 lg:block">
        <button type="button" @click="collapsed = ! collapsed; persistCollapsed()"
                class="focusable flex h-11 w-full items-center gap-3.5 rounded-xl px-3.5 text-[15.5px] font-medium text-muted transition-colors hover:bg-surface-2 hover:text-ink-2"
                :class="collapsed && 'lg:justify-center lg:px-0'">
            <x-icon name="chevron-left" class="size-[21px] shrink-0 transition-transform"
                    ::class="collapsed && 'rotate-180'" />
            <span :class="collapsed && 'lg:hidden'">Collapse</span>
        </button>
    </div>
</aside>
