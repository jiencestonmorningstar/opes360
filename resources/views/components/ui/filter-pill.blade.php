@props([
    'label',
    'icon' => null,
    'iconClass' => '',
    'variant' => 'outline',
])

{{--
    Two variants from the design: the bordered "Today" pill beside the greeting,
    and the borderless blue "This Week" / "This Month" control inside panels.

    `iconClass` exists because the design shows the calendar glyph only in the
    desktop composition — one pill that adapts, rather than two that duplicate.
--}}
@php
    // The ghost variant carried no padding, so its hit area was the 21px the
    // text happened to occupy. The padding is negative-margined back out, so
    // the target grows without the layout moving.
    $classes = $variant === 'ghost'
        ? '-mr-1.5 gap-1 rounded-lg px-1.5 py-1.5 text-[14px] font-semibold text-brand'
        : 'gap-2 rounded-full border border-border bg-surface px-4 py-2.5 text-[14.5px] font-semibold text-ink shadow-[var(--shadow-card)]';
@endphp

<button type="button" {{ $attributes->merge(['class' => 'focusable inline-flex shrink-0 items-center '.$classes]) }}>
    @if ($icon)
        <x-icon :name="$icon" class="size-[19px] text-muted {{ $iconClass }}" />
    @endif

    <span>{{ $label }}</span>

    <x-icon name="chevron-down" class="{{ $variant === 'ghost' ? 'size-[16px]' : 'size-[17px] text-muted' }}"
            stroke-width="2.2" />
</button>
