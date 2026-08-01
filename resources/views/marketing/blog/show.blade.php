<x-layouts.marketing :title="$post['title'].' · '.config('opes.brand.name').' Blog'" :description="$post['excerpt']">
<div class="mx-auto max-w-2xl px-5 py-16 sm:py-20">
    <a href="{{ route('marketing.blog') }}" class="focusable inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand hover:underline">
        <x-icon name="chevron-left" class="size-[16px]" stroke-width="2.2" />
        All posts
    </a>

    <p class="mt-6 text-[12.5px] font-medium text-faint">
        {{ \Illuminate\Support\Carbon::parse($post['published_at'])->format('F j, Y') }}
        · {{ $post['read_minutes'] }} min read
    </p>
    <h1 class="mt-2 text-[28px] font-bold leading-tight tracking-[-0.02em] text-ink sm:text-[34px]">{{ $post['title'] }}</h1>

    <div class="prose mt-8 text-[15.5px] text-ink-2">
        {!! $bodyHtml !!}
    </div>

    <div class="mt-12 border-t border-border pt-8 text-center">
        <p class="text-[15px] font-semibold text-ink">See it running with your own login.</p>
        <a href="{{ route('demo.request') }}"
           class="tap focusable mt-4 inline-flex h-11 items-center justify-center rounded-xl bg-fill-brand px-6 text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
            Try a demo
        </a>
    </div>
</div>
</x-layouts.marketing>
