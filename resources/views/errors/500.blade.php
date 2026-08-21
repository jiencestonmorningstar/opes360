<x-layouts.public title="Something went wrong">
<div class="w-full max-w-[420px] text-center">
    <span class="mx-auto flex size-[70px] items-center justify-center rounded-full bg-tint-red">
        <x-icon name="alert" class="size-8 text-negative" stroke-width="1.8" />
    </span>

    <h1 class="mt-5 text-[21px] font-bold tracking-[-0.02em] text-ink">Something went wrong on our end</h1>
    <p class="mt-2 text-[14px] leading-relaxed text-muted">
        Nothing you did caused this. It has already been recorded — try again in a moment.
    </p>

    <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
        <button type="button" onclick="window.location.reload()"
                class="tap focusable flex h-11 w-full items-center justify-center rounded-xl bg-fill-brand px-6 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
            Try again
        </button>
        <a href="{{ route('dashboard') }}"
           class="tap focusable flex h-11 w-full items-center justify-center rounded-xl border border-border bg-surface px-6 text-[14.5px] font-semibold text-ink transition-colors hover:border-brand/40 sm:w-auto">
            Back home
        </a>
    </div>
</div>
</x-layouts.public>
