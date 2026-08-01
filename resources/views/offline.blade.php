<x-layouts.public title="Offline" width="max-w-[400px]">
    <div class="card flex flex-col items-center p-8 text-center">
        <span class="flex size-[70px] items-center justify-center rounded-full bg-tint-orange">
            <x-icon name="offline" class="size-8 text-warning" stroke-width="1.8" />
        </span>

        <h1 class="mt-4 text-[21px] font-bold tracking-[-0.02em] text-ink">You're offline</h1>
        <p class="mt-2 text-[14px] leading-relaxed text-muted">
            This page hasn't been saved to your device yet. Pages you've already visited stay available,
            and anything you record while offline syncs the moment you're back.
        </p>

        <button type="button" onclick="window.location.reload()"
                class="tap focusable mt-6 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white hover:opacity-90">
            Try again
        </button>

        <a href="/" class="focusable mt-3 text-[14px] font-semibold text-brand hover:underline">
            Go to dashboard
        </a>
    </div>

    <p class="mt-6 text-center text-[12px] text-faint">
        <span class="font-semibold"><span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span></span>
        works offline by design.
    </p>
</x-layouts.public>
