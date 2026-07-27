@props([
    'label',
    'value',
    'icon',
    'accent' => 'blue',
    'delta' => null,
    'deltaTone' => 'positive',
    'deltaCaption' => 'vs yesterday',
])

@php
    use App\Support\Accent;

    // Mobile follows the design's tinted cards; from `lg` the design switches to a
    // white card with a border, so the tint is dropped there and in dark mode.
    $surface = Accent::tint($accent).' border-transparent dark:bg-surface dark:border-border lg:bg-surface lg:border-border';

    $deltaColour = $deltaTone === 'warning' ? 'text-warning' : 'text-positive';
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl border p-4 lg:p-[18px] '.$surface]) }}>
    <div class="flex flex-col lg:flex-row lg:items-start lg:gap-3.5">
        <span class="mb-3 flex size-[46px] shrink-0 items-center justify-center rounded-[14px] {{ Accent::solid($accent) }} text-white shadow-sm lg:mb-0 lg:size-[50px]">
            <x-icon :name="$icon" class="size-[25px]" stroke-width="1.9" />
        </span>

        <div class="min-w-0">
            <p class="truncate text-[13px] font-medium text-muted">{{ $label }}</p>

            <p class="tnum mt-1 text-[26px] font-bold leading-tight tracking-[-0.02em] text-ink">
                {{ $value }}
            </p>

            @if ($delta)
                <p class="mt-1.5 flex items-center gap-1 text-[12px] leading-none">
                    <x-icon name="arrow-up" class="size-[13px] {{ $deltaColour }}" stroke-width="2.6" />
                    <span class="font-semibold {{ $deltaColour }}">{{ $delta }}</span>
                    <span class="truncate text-muted">{{ $deltaCaption }}</span>
                </p>
            @endif
        </div>
    </div>
</div>
