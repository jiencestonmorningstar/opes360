@php
    use App\Support\Money;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div>
        <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Calendar</h1>
        <p class="mt-1 text-[14.5px] text-muted">What's due, what's late, what's about to expire.</p>
    </div>

    <div class="mt-5 space-y-4">

        {{-- Overdue leads: it is the only section demanding action today. --}}
        @if ($overdue->isNotEmpty())
            <x-ui.panel body-class="-mx-1.5">
                <x-slot:actions>
                    <span class="tnum text-[13.5px] font-bold text-negative">
                        {{ Money::format($overdue->sum('balance'), $overdue->first()->currency) }} late
                    </span>
                </x-slot:actions>
                <x-slot name="title">Overdue</x-slot>

                @foreach ($overdue as $document)
                    <a href="{{ route('documents.show', $document) }}" wire:key="od-{{ $document->id }}"
                       class="focusable flex items-center gap-3 rounded-lg px-1.5 py-2.5 hover:bg-surface-2 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                        <span class="flex size-[42px] shrink-0 flex-col items-center justify-center rounded-xl bg-tint-orange">
                            <span class="text-[15px] font-bold leading-none text-warning">{{ $document->due_date->format('j') }}</span>
                            <span class="text-[10px] font-semibold uppercase leading-none text-warning">{{ $document->due_date->format('M') }}</span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14.5px] font-semibold text-ink">{{ $document->contact?->displayName() ?? 'Walk-in' }}</span>
                            <span class="block truncate text-[12.5px] text-negative">
                                {{ $document->number }} · {{ $document->due_date->diffForHumans(null, true) }} late
                            </span>
                        </span>
                        <span class="tnum shrink-0 text-[14.5px] font-bold text-ink">{{ Money::format($document->balance, $document->currency) }}</span>
                        <x-icon name="chevron-right" class="size-[16px] shrink-0 text-faint" stroke-width="2" />
                    </a>
                @endforeach
            </x-ui.panel>
        @endif

        {{-- Upcoming, grouped by due date --}}
        <x-ui.panel title="Due in the next 30 days" body-class="-mx-1.5">
            @forelse ($upcoming as $date => $documents)
                @php $day = \Illuminate\Support\Carbon::parse($date); @endphp
                <div wire:key="day-{{ $date }}" class="{{ ! $loop->first ? 'mt-4 border-t border-border pt-3' : '' }}">
                    <p class="px-1.5 text-[12px] font-semibold uppercase tracking-wide text-faint">
                        {{ $day->isToday() ? 'Today' : ($day->isTomorrow() ? 'Tomorrow' : $day->format('D, M j')) }}
                    </p>

                    @foreach ($documents as $document)
                        <a href="{{ route('documents.show', $document) }}" wire:key="up-{{ $document->id }}"
                           class="focusable mt-1 flex items-center gap-3 rounded-lg px-1.5 py-2.5 hover:bg-surface-2">
                            <span class="flex size-[38px] shrink-0 items-center justify-center rounded-xl bg-tint-blue">
                                <x-icon name="document" class="size-[19px] text-accent-blue" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14.5px] font-semibold text-ink">{{ $document->contact?->displayName() ?? 'Walk-in' }}</span>
                                <span class="block truncate text-[12.5px] text-muted">{{ $document->number }}</span>
                            </span>
                            <span class="tnum shrink-0 text-[14.5px] font-bold text-ink">{{ Money::format($document->balance, $document->currency) }}</span>
                            <x-icon name="chevron-right" class="size-[16px] shrink-0 text-faint" stroke-width="2" />
                        </a>
                    @endforeach
                </div>
            @empty
                <div class="flex flex-col items-center px-6 py-12 text-center">
                    <span class="flex size-[54px] items-center justify-center rounded-full bg-tint-green">
                        <x-icon name="check-circle" class="size-7 text-accent-green" />
                    </span>
                    <p class="mt-3 text-[15px] font-semibold text-ink">Nothing due</p>
                    <p class="mt-1 text-[13px] text-muted">No invoices fall due in the next 30 days.</p>
                </div>
            @endforelse
        </x-ui.panel>

        {{-- Quotations nearing expiry --}}
        @if ($expiring->isNotEmpty())
            <x-ui.panel title="Quotations expiring soon" body-class="-mx-1.5">
                @foreach ($expiring as $document)
                    <a href="{{ route('documents.show', $document) }}" wire:key="ex-{{ $document->id }}"
                       class="focusable flex items-center gap-3 rounded-lg px-1.5 py-2.5 hover:bg-surface-2 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                        <span class="flex size-[38px] shrink-0 items-center justify-center rounded-xl bg-tint-purple">
                            <x-icon name="clock" class="size-[19px] text-accent-purple" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14.5px] font-semibold text-ink">{{ $document->contact?->displayName() ?? 'Walk-in' }}</span>
                            <span class="block truncate text-[12.5px] text-muted">
                                {{ $document->number }} · expires {{ $document->valid_until->format('M j') }}
                            </span>
                        </span>
                        <span class="tnum shrink-0 text-[14.5px] font-bold text-ink">{{ Money::format($document->total, $document->currency) }}</span>
                    </a>
                @endforeach
            </x-ui.panel>
        @endif
    </div>
</div>
