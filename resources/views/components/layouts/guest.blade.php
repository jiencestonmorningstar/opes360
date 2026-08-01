@props(['title' => null, 'width' => 'max-w-[480px]'])

{{--
    Minimal shell for pre-auth pages: no nav, no tenant, just a centred column.

    Kept under its own name because much of the app asks for it that way, but it
    is now a preset over the shared public shell rather than a second copy of the
    document head.
--}}
<x-layouts.public :title="$title" :width="$width">
    {{ $slot }}
</x-layouts.public>
