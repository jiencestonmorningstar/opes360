<x-layouts.public title="Page expired">
<div class="w-full max-w-[420px] text-center">
    <span class="mx-auto flex size-[70px] items-center justify-center rounded-full bg-tint-orange">
        <x-icon name="clock" class="size-8 text-warning" stroke-width="1.8" />
    </span>

    <h1 class="mt-5 text-[21px] font-bold tracking-[-0.02em] text-ink">This page timed out</h1>
    <p class="mt-2 text-[14px] leading-relaxed text-muted">
        You were away for a while, so the session on this page expired. Nothing was lost —
        just reload and carry on.
    </p>

    <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
        <button type="button" onclick="window.location.reload()"
                class="tap focusable flex h-11 w-full items-center justify-center rounded-xl bg-fill-brand px-6 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
            Reload
        </button>
        <a href="{{ route('dashboard') }}"
           class="tap focusable flex h-11 w-full items-center justify-center rounded-xl border border-border bg-surface px-6 text-[14.5px] font-semibold text-ink transition-colors hover:border-brand/40 sm:w-auto">
            Back home
        </a>
    </div>
</div>
</x-layouts.public>
