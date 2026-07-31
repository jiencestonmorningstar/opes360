<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Reviews</h1>
            <p class="mt-1 text-[14.5px] text-muted">What visitors submit on your public page. Nothing shows until you publish it.</p>
        </div>

        <a href="{{ route('profile.business', app(App\Support\CurrentCompany::class)->get()) }}" target="_blank"
           class="tap focusable hidden shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14px] font-semibold text-ink-2 hover:bg-surface-2 min-[560px]:flex">
            Public page
            <x-icon name="chevron-right" class="size-[15px]" stroke-width="2.4" />
        </a>
    </div>

    @include('partials.business-tabs')

    <div class="mt-5 grid gap-4 lg:grid-cols-2 lg:items-start">

        <x-ui.panel title="Pending ({{ $pending->count() }})">
            @forelse ($pending as $review)
                <div wire:key="pend-{{ $review->id }}" class="{{ $loop->first ? '' : 'mt-3' }} rounded-xl bg-surface-2 p-4">
                    <span class="text-[13px] tracking-[0.15em]" aria-label="{{ $review->rating }} out of 5 stars">
                        <span class="text-warning">{{ str_repeat('★', $review->rating) }}</span><span class="text-faint">{{ str_repeat('☆', 5 - $review->rating) }}</span>
                    </span>
                    <p class="mt-2 text-[13.5px] leading-relaxed text-ink-2">{{ $review->body }}</p>
                    <p class="mt-2 text-[12px] font-semibold text-muted">
                        {{ $review->author_name }} · {{ $review->created_at->format('j M Y') }}
                    </p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="publish('{{ $review->id }}')"
                                class="focusable flex h-9 items-center rounded-full bg-brand px-4 text-[13px] font-semibold text-white hover:opacity-90">
                            Publish
                        </button>
                        <button type="button" wire:click="delete('{{ $review->id }}')" wire:confirm="Delete this review permanently?"
                                class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-warning hover:bg-surface-2">
                            Delete
                        </button>
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">Nothing waiting for approval.</p>
            @endforelse
        </x-ui.panel>

        <x-ui.panel title="Published ({{ $published->count() }})">
            @forelse ($published as $review)
                <div wire:key="pub-{{ $review->id }}" class="{{ $loop->first ? '' : 'mt-3' }} rounded-xl bg-surface-2 p-4">
                    <span class="text-[13px] tracking-[0.15em]" aria-label="{{ $review->rating }} out of 5 stars">
                        <span class="text-warning">{{ str_repeat('★', $review->rating) }}</span><span class="text-faint">{{ str_repeat('☆', 5 - $review->rating) }}</span>
                    </span>
                    <p class="mt-2 text-[13.5px] leading-relaxed text-ink-2">{{ $review->body }}</p>
                    <p class="mt-2 text-[12px] font-semibold text-muted">
                        {{ $review->author_name }} · {{ $review->created_at->format('j M Y') }}
                    </p>
                    <div class="mt-3 flex gap-2">
                        <button type="button" wire:click="unpublish('{{ $review->id }}')"
                                class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                            Unpublish
                        </button>
                        <button type="button" wire:click="delete('{{ $review->id }}')" wire:confirm="Delete this review permanently?"
                                class="focusable flex h-9 items-center rounded-full border border-border bg-surface px-4 text-[13px] font-semibold text-warning hover:bg-surface-2">
                            Delete
                        </button>
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">No published reviews yet.</p>
            @endforelse
        </x-ui.panel>
    </div>
</div>
