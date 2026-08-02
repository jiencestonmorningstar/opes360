<x-layouts.marketing title="Blog"
                     :description="'Notes on offline-first commerce, QR verification, loyalty and running a business on '.config('opes.brand.name').'.'">
<x-marketing.page-header eyebrow="Blog" title="Notes from building this"
    :lead="'Offline-first commerce, what a QR on an invoice actually proves, loyalty that people use, and running a business on '.config('opes.brand.name').'.'" />

    <section class="mx-auto max-w-3xl px-5 py-12 sm:py-16">
        <div class="space-y-5">
            @foreach ($posts as $slug => $post)
                <a href="{{ route('marketing.blog.show', $slug) }}"
                   class="card focusable block p-6 transition-colors hover:border-brand/40">
                    <p class="text-[12.5px] font-medium text-faint">
                        {{ \Illuminate\Support\Carbon::parse($post['published_at'])->format('F j, Y') }}
                        · {{ $post['read_minutes'] }} min read
                    </p>
                    <h2 class="mt-1.5 text-[19px] font-bold tracking-[-0.01em] text-ink">{{ $post['title'] }}</h2>
                    <p class="mt-2 text-[14.5px] leading-relaxed text-muted">{{ $post['excerpt'] }}</p>
                    <span class="mt-3 inline-flex items-center gap-1 text-[13.5px] font-semibold text-brand">
                        Read more
                        <x-icon name="chevron-right" class="size-[15px]" stroke-width="2.2" />
                    </span>
                </a>
            @endforeach
        </div>
    </section>
</x-layouts.marketing>
