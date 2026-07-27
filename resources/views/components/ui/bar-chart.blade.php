@props([
    'series' => [],
    'height' => 'h-[104px] lg:h-[118px]',
])

@php
    // Bars are proportional to the largest value in the window, so a quiet week
    // still reads as a full-height chart rather than a flat line.
    $max = max(1, collect($series)->max('value'));
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }}>
    <div class="flex {{ $height }} items-end justify-between" role="img"
         aria-label="{{ collect($series)->map(fn ($p) => $p['label'].': '.number_format($p['value'])) ->implode(', ') }}">
        @foreach ($series as $point)
            @php
                // Floor at 6% so a zero-value day is still a visible tick rather
                // than an invisible gap the eye reads as missing data.
                $pct = max(6, round(($point['value'] / $max) * 100));
                $isPeak = $point['highlight'] ?? false;
            @endphp
            {{-- The bar occupies just over half its column, which is the bar-to-gap
                 ratio in the design. --}}
            <div class="flex h-full flex-1 items-end justify-center">
                <div class="w-[55%] rounded-t-[5px] {{ $isPeak ? 'bg-brand' : 'bg-brand/25 dark:bg-brand/40' }}"
                     style="height: {{ $pct }}%"></div>
            </div>
        @endforeach
    </div>

    <div class="mt-2.5 flex justify-between">
        @foreach ($series as $point)
            <span class="flex-1 text-center text-[11.5px] font-medium text-muted">{{ $point['label'] }}</span>
        @endforeach
    </div>
</div>
