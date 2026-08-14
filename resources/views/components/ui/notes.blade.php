@props(['text' => null])

@php
    $blocks = \App\Support\DocumentNotes::parse($text);
@endphp

@if ($blocks !== [])
    <div {{ $attributes->merge(['class' => 'space-y-3 text-[13.5px] leading-relaxed text-ink-2']) }}>
        @foreach ($blocks as $block)
            @switch($block['type'])
                @case('heading')
                    {{-- Tight to what follows it: a heading that floats equidistant
                         between two sections belongs to neither. --}}
                    <p class="!mt-5 text-[12px] font-bold uppercase tracking-[0.06em] text-ink first:!mt-0">
                        {{ $block['text'] }}
                    </p>
                    @break

                @case('rule')
                    <hr class="!my-4 border-0 border-t border-border">
                    @break

                @case('bullets')
                    <ul class="space-y-1.5">
                        @foreach ($block['items'] as $item)
                            <li class="flex gap-2.5">
                                <span class="mt-[7px] size-[5px] shrink-0 rounded-full bg-border-strong"></span>
                                <span class="min-w-0">{{ $item }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @break

                @case('numbers')
                    <ol class="space-y-1.5">
                        @foreach ($block['items'] as $index => $item)
                            <li class="flex gap-2.5">
                                <span class="tnum mt-px w-4 shrink-0 text-[12.5px] font-semibold text-faint">{{ $index + 1 }}.</span>
                                <span class="min-w-0">{{ $item }}</span>
                            </li>
                        @endforeach
                    </ol>
                    @break

                @default
                    <p>{{ $block['text'] }}</p>
            @endswitch
        @endforeach
    </div>
@endif
