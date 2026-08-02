@props(['eyebrow' => null, 'title', 'lead' => null, 'wash' => 'brand'])

{{--
    The opening block every marketing page below the home page shares.

    Left-aligned, with the same wash behind it as the home hero, so the pages
    read as one site rather than as a landing page with a set of documents
    stapled to it.
--}}
<section class="relative overflow-hidden border-b border-border">
    <div aria-hidden="true"
         class="pointer-events-none absolute inset-x-0 -top-40 h-[380px] bg-gradient-to-b {{ $wash === 'purple' ? 'from-accent-purple/[0.09]' : 'from-brand/[0.07]' }} to-transparent"></div>

    <div class="relative mx-auto max-w-6xl px-5 pb-12 pt-14 sm:pb-16 sm:pt-20">
        <div class="max-w-2xl">
            @if ($eyebrow)
                <p class="text-[12.5px] font-semibold uppercase tracking-[0.08em] {{ $wash === 'purple' ? 'text-accent-purple' : 'text-brand' }}">{{ $eyebrow }}</p>
            @endif
            <h1 class="mt-3 text-[32px] font-bold leading-[1.1] tracking-[-0.025em] text-ink sm:text-[42px]">{{ $title }}</h1>
            @if ($lead)
                <p class="mt-5 text-[16px] leading-relaxed text-muted sm:text-[17px]">{{ $lead }}</p>
            @endif
        </div>
    </div>
</section>
