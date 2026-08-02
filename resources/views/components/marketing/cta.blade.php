@props([
    'title' => 'Set it up this afternoon',
    'lead' => 'Register the business, add a customer and issue an invoice — about ten minutes, and there is no card to enter.',
    'secondary' => 'contact',
])

{{-- The closing block, identical on every page so the site ends the same way
     wherever someone stops reading. --}}
<section class="border-t border-border">
    <div class="mx-auto max-w-3xl px-5 py-16 text-center sm:py-20">
        <h2 class="text-[26px] font-bold leading-tight tracking-[-0.025em] text-ink sm:text-[32px]">{{ $title }}</h2>
        <p class="mx-auto mt-4 max-w-lg text-[15.5px] leading-relaxed text-muted">{{ $lead }}</p>

        <div class="mt-8 flex flex-col justify-center gap-3 sm:flex-row">
            <a href="{{ route('demo.request') }}"
               class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                Try a demo
            </a>
            <a href="{{ $secondary === 'pricing' ? route('marketing.pricing') : route('marketing.contact') }}"
               class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink transition-colors hover:border-brand/40">
                {{ $secondary === 'pricing' ? 'See pricing' : 'Talk to us first' }}
            </a>
        </div>
    </div>
</section>
