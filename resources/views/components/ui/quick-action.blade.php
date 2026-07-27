@props([
    'label',
    'icon',
    'accent' => 'blue',
    'href' => '#',
])

@php
    use App\Support\Accent;
@endphp

<a href="{{ $href }}"
   {{ $attributes->merge(['class' => 'focusable group flex flex-col items-center gap-2.5 rounded-xl py-1 text-center']) }}>
    <span class="flex size-[58px] items-center justify-center rounded-full {{ Accent::tint($accent) }} transition-transform group-active:scale-95 lg:size-[62px]">
        <x-icon :name="$icon" class="size-[26px] {{ Accent::text($accent) }}" stroke-width="1.7" />
    </span>

    <span class="text-[12.5px] font-medium leading-tight text-ink-2 lg:text-[13.5px]">{{ $label }}</span>
</a>
