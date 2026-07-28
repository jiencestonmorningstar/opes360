@php
    $primary = \App\Support\Navigation::primary();
@endphp

{{--
    Fixed thumb-reach navigation. Hidden from `lg` up, where the sidebar takes over.
    The safe-area padding keeps the labels clear of the iOS home indicator.
--}}
<nav class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-surface lg:hidden"
     style="padding-bottom: env(safe-area-inset-bottom)"
     aria-label="Primary">
    {{-- The column count follows the permitted entries, so a restricted role
         gets an evenly spaced bar rather than a gap where a link used to be. --}}
    <ul class="grid" style="grid-template-columns: repeat({{ $primary->count() + 1 }}, minmax(0, 1fr))">
        @foreach ($primary as $item)
            @php
                $isActive = $active === $item['key'];
                $href = $item['route'] ? route($item['route']) : '#';
            @endphp
            <li>
                <a href="{{ $href }}"
                   @if ($isActive) aria-current="page" @endif
                   class="tap flex flex-col items-center justify-center gap-1 pt-2.5 pb-2 {{ $isActive ? 'text-brand' : 'text-muted' }}">
                    <x-icon :name="$item['icon']" class="size-[25px]" :solid="$isActive" />
                    <span class="text-[11.5px] font-medium leading-none">{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach

        <li>
            <button type="button" @click="drawer = true"
                    class="tap flex w-full flex-col items-center justify-center gap-1 pt-2.5 pb-2 text-muted">
                <x-icon name="ellipsis" class="size-[25px]" />
                <span class="text-[11.5px] font-medium leading-none">More</span>
            </button>
        </li>
    </ul>
</nav>
