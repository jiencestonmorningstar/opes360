@props([
    'title' => null,
    'action' => null,
    'actionHref' => null,
    'bodyClass' => '',
])

{{--
    The section container used by every dashboard block: card surface, 20px
    padding, 17px bold title, and either a blue text link (`action`) or an
    arbitrary control (the `actions` slot, used for the range pills) on the right.
--}}
<section {{ $attributes->merge(['class' => 'card p-5']) }}>
    @if ($title || $action || isset($actions))
        <div class="mb-4 flex items-center justify-between gap-3">
            @if ($title)
                <h2 class="text-[17px] font-bold tracking-[-0.01em] text-ink">{{ $title }}</h2>
            @endif

            @isset($actions)
                {{ $actions }}
            @elseif ($action && $actionHref)
                <a href="{{ $actionHref }}"
                   class="focusable -mr-1.5 shrink-0 rounded-lg px-1.5 py-1.5 text-[14px] font-semibold text-brand hover:underline">
                    {{ $action }}
                </a>
            @endisset
        </div>
    @endif

    <div class="{{ $bodyClass }}">{{ $slot }}</div>
</section>
