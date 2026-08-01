@props([
    'title' => null,
    'description' => null,
    // Marketing pages that are really a single form or confirmation (demo
    // request, thank-you) want the nav and footer but a centred column, not
    // full-bleed sections.
    'variant' => 'page',
    'width' => 'max-w-[480px]',
])

{{--
    The public site: shared nav, the page, shared footer.

    A thin wrapper over <x-layouts.public> rather than a second document shell,
    so the head, the theme bootstrap and the page background stay defined in
    exactly one place.
--}}
<x-layouts.public :title="$title" :description="$description" variant="page">
    @include('marketing.partials.nav')

    @if ($variant === 'page')
        <main>{{ $slot }}</main>
    @else
        <main class="mx-auto flex w-full flex-col items-center px-5 pb-16 pt-10">
            <div class="w-full {{ $width }}">
                {{ $slot }}
            </div>
        </main>
    @endif

    @include('marketing.partials.footer')
</x-layouts.public>
