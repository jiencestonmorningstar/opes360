@php use App\Support\Money; @endphp

<x-layouts.public :title="$event->title.' · Tickets'" robots="noindex" width="max-w-[560px]" variant="bare">
    <div class="card overflow-hidden border-t-4 border-t-brand">
        <div class="p-5">
            <p class="text-[11.5px] font-semibold uppercase tracking-wide text-faint">{{ $company->name }} presents</p>
            <h1 class="mt-1 text-[21px] font-bold leading-tight tracking-[-0.02em] text-ink">{{ $event->title }}</h1>
            <p class="mt-1.5 text-[13.5px] text-muted">
                {{ $event->starts_at->format('D, F j, Y · g:ia') }}@if ($event->venue) · {{ $event->venue }}@endif
            </p>

            @if ($event->status === 'cancelled')
                <p class="mt-4 text-[14px] font-semibold text-warning">This event has been cancelled.</p>
            @elseif (! $event->isSelling())
                <p class="mt-4 text-[14px] font-semibold text-ink">Ticket sales have closed.</p>
            @else
                <div class="mt-4 space-y-2">
                    @foreach ($types as $type)
                        <div class="flex items-center justify-between gap-3 rounded-xl border border-border px-3.5 py-2.5">
                            <span class="min-w-0 truncate text-[13.5px] font-semibold text-ink">{{ $type->name }}</span>
                            <span class="shrink-0 text-[13px] font-semibold {{ $type->isSoldOut() ? 'uppercase text-faint' : 'text-ink-2' }}">
                                {{ $type->isSoldOut() ? 'Sold out' : ((float) $type->price > 0 ? Money::format($type->price, $company->currency) : 'Free') }}
                            </span>
                        </div>
                    @endforeach
                </div>

                {{-- Opens as its own tab: ticket delivery needs a first-party
                     session that a third-party iframe is not allowed to hold. --}}
                <a href="{{ $event->publicUrl() }}" target="_blank" rel="noopener"
                   class="tap focusable mt-4 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                    Get tickets
                </a>
            @endif
        </div>

        <p class="border-t border-border px-5 py-2.5 text-center text-[11px] text-faint">
            Ticketed with <span class="font-semibold"><span class="text-ink">{{ config('opes.brand.name_prefix') }}</span><span class="text-brand">{{ config('opes.brand.name_suffix') }}</span></span>
        </p>
    </div>
</x-layouts.public>
