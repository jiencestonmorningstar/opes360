<x-layouts.public title="Page not found">
<div class="w-full max-w-[420px] text-center">
    <span class="mx-auto flex size-[70px] items-center justify-center rounded-full bg-tint-blue">
        <x-icon name="search" class="size-8 text-brand" stroke-width="1.8" />
    </span>

    <h1 class="mt-5 text-[21px] font-bold tracking-[-0.02em] text-ink">We couldn't find that page</h1>
    <p class="mt-2 text-[14px] leading-relaxed text-muted">
        The link may be old, or the address was typed wrong. Nothing here has been lost.
    </p>

    <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
        <a href="{{ route('dashboard') }}"
           class="tap focusable flex h-11 w-full items-center justify-center rounded-xl bg-fill-brand px-6 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
            Back home
        </a>
    </div>
</div>
</x-layouts.public>
