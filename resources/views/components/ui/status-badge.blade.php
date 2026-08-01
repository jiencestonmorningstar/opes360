@props([
    'label',
    'tone' => 'neutral',
])

@php
    [$text, $dot] = match ($tone) {
        'positive' => ['text-positive', 'bg-fill-positive'],
        'warning' => ['text-warning', 'bg-fill-warning'],
        'negative' => ['text-negative', 'bg-fill-negative'],
        'muted' => ['text-faint', 'bg-faint'],
        default => ['text-muted', 'bg-muted'],
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 text-[12.5px] font-semibold '.$text]) }}>
    {{ $label }}
    <span class="size-[7px] rounded-full {{ $dot }}" aria-hidden="true"></span>
</span>
