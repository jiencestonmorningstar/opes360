@php
    // AuthorizationException carries whatever message Gate::before/policies
    // returned. A plan denial always mentions "plan" (see
    // AuthServiceProvider); anything else is an ordinary permission refusal.
    $message = $exception->getMessage();
    $isPlanDenial = str_contains($message, 'plan');
@endphp

<x-layouts.public :title="$isPlanDenial ? 'Upgrade required' : 'Not permitted'">
<div class="w-full max-w-[420px] text-center">
    <span class="mx-auto flex size-[70px] items-center justify-center rounded-full {{ $isPlanDenial ? 'bg-tint-blue' : 'bg-tint-orange' }}">
        <x-icon :name="$isPlanDenial ? 'spark' : 'alert'" class="size-8 {{ $isPlanDenial ? 'text-brand' : 'text-warning' }}" stroke-width="1.8" />
    </span>

    <h1 class="mt-5 text-[21px] font-bold tracking-[-0.02em] text-ink">
        {{ $isPlanDenial ? 'Not on your plan yet' : "You don't have access to that" }}
    </h1>
    <p class="mt-2 text-[14px] leading-relaxed text-muted">
        {{ $message !== '' ? $message : 'Ask a manager or the business owner if you need this.' }}
    </p>

    <div class="mt-7 flex flex-col items-center justify-center gap-3 sm:flex-row">
        @if ($isPlanDenial)
            <a href="{{ route('marketing.pricing') }}"
               class="tap focusable flex h-11 w-full items-center justify-center rounded-xl bg-fill-brand px-6 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
                See plans
            </a>
        @endif
        <a href="{{ route('dashboard') }}"
           class="tap focusable flex h-11 w-full items-center justify-center rounded-xl border border-border bg-surface px-6 text-[14.5px] font-semibold text-ink transition-colors hover:border-brand/40 sm:w-auto">
            Back home
        </a>
    </div>
</div>
</x-layouts.public>
