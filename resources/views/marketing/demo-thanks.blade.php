<x-layouts.marketing title="Check your email" variant="column">
<div class="w-full max-w-[440px]">
    <div class="card flex flex-col items-center p-8 text-center">
        <span class="flex size-[74px] items-center justify-center rounded-full bg-tint-green">
            <x-icon name="check-circle" class="size-9 text-positive" stroke-width="1.8" />
        </span>
        <h1 class="mt-4 text-[23px] font-bold tracking-[-0.02em] text-ink">Check your email</h1>
        <p class="mt-1.5 text-[14px] leading-snug text-muted">
            Your demo account is ready. We've sent the login details
            @if ($email)to <strong class="font-semibold text-ink-2">{{ $email }}</strong>@endif.
        </p>
        <a href="{{ route('login') }}"
           class="tap focusable mt-6 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
            Go to sign in
        </a>
    </div>
</div>
</x-layouts.marketing>
