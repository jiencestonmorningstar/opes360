<x-layouts.public title="Down for maintenance">
<div class="w-full max-w-[420px] text-center">
    <span class="mx-auto flex size-[70px] items-center justify-center rounded-full bg-tint-blue">
        <x-icon name="cog" class="size-8 text-brand" stroke-width="1.8" />
    </span>

    <h1 class="mt-5 text-[21px] font-bold tracking-[-0.02em] text-ink">Back in a moment</h1>
    <p class="mt-2 text-[14px] leading-relaxed text-muted">
        We're carrying out some scheduled maintenance. Your data is safe — this
        should only take a few minutes.
    </p>

    <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
        <button type="button" onclick="window.location.reload()"
                class="tap focusable flex h-11 w-full items-center justify-center rounded-xl bg-fill-brand px-6 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
            Try again
        </button>
    </div>
</div>
</x-layouts.public>
