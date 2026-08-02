@php
    use App\Support\Accent;
    use App\Support\Money;

    $accent = $contact->accent();
    $currency = app(\App\Support\CurrentCompany::class)->get()?->currency ?? 'USD';
    $phone = data_get($contact->phones, 0);
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center gap-3">
        <a href="{{ route('customers') }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink" aria-label="Back">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>
        <h1 class="min-w-0 flex-1 truncate text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
            {{ $contact->displayName() }}
        </h1>

        {{-- The route, the form and the policy for editing a customer have all
             existed since the CRM shipped, and nothing anywhere linked to them —
             so a misspelled name or a changed phone number could not be fixed
             from inside the product at all. --}}
        @can('update', $contact)
            <a href="{{ route('customers.edit', $contact) }}" wire:navigate
               class="tap focusable flex h-11 shrink-0 items-center rounded-xl border border-border bg-surface px-4 text-[14px] font-semibold text-ink hover:bg-surface-2">
                Edit
            </a>
        @endcan
    </div>

    <div class="mt-5 grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">

            {{-- Identity + quick contact --}}
            <section class="card p-5">
                <div class="flex items-center gap-4">
                    <span class="flex size-[58px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }} text-[18px] font-bold {{ Accent::text($accent) }}">
                        {{ $contact->initials() }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[16.5px] font-bold text-ink">{{ $contact->displayName() }}</p>
                        <p class="truncate text-[13px] text-muted">
                            {{ implode(' · ', array_filter([$contact->email, $phone])) ?: ucfirst($contact->type) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        @if ($phone)
                            <a href="tel:{{ $phone }}" class="tap focusable flex items-center justify-center rounded-xl bg-tint-blue text-accent-blue" aria-label="Call">
                                <x-icon name="bell" class="size-[20px]" />
                            </a>
                        @endif
                        @if ($contact->email)
                            <a href="mailto:{{ $contact->email }}" class="tap focusable flex items-center justify-center rounded-xl bg-tint-purple text-accent-purple" aria-label="Email">
                                <x-icon name="document" class="size-[20px]" />
                            </a>
                        @endif
                    </div>
                </div>

                {{-- See the note in payments/index: a currency figure cannot be
                     broken, so three of them do not fit a 360px screen. --}}
                <div class="mt-5 grid grid-cols-1 gap-3 border-t border-border pt-4 min-[400px]:grid-cols-3">
                    @foreach ([
                        ['Total billed', $stats['billed'], 'text-ink'],
                        ['Paid', $stats['paid'], 'text-positive'],
                        ['Owing', $stats['owing'], $stats['owing'] > 0 ? 'text-warning' : 'text-muted'],
                    ] as [$label, $value, $colour])
                        <div>
                            <p class="text-[12px] font-medium text-muted">{{ $label }}</p>
                            <p class="tnum mt-0.5 text-[17px] font-bold tracking-[-0.02em] {{ $colour }}">
                                {{ Money::format($value, $currency) }}
                            </p>
                        </div>
                    @endforeach
                </div>

                <a href="{{ route('customers.statement', $contact) }}" target="_blank" rel="noopener"
                   class="focusable mt-4 flex h-11 w-full items-center justify-center gap-2 rounded-xl border border-border bg-surface text-[13.5px] font-semibold text-ink-2 transition-colors hover:bg-surface-2">
                    <x-icon name="printer" class="size-[18px]" stroke-width="1.9" />
                    Statement of account
                </a>
            </section>

            {{-- Documents --}}
            <x-ui.panel title="Documents" body-class="-mx-1.5">
                @forelse ($documents as $index => $document)
                    @php $state = $document->paymentState(); @endphp
                    <a href="{{ route('documents.show', $document) }}" wire:key="doc-{{ $document->id }}"
                       class="focusable flex items-center gap-3 rounded-lg px-1.5 py-2.5 hover:bg-surface-2 {{ $index > 0 ? 'border-t border-border' : '' }}">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14.5px] font-semibold text-ink">{{ $document->number ?? 'Draft' }}</span>
                            <span class="block text-[12.5px] text-muted">{{ $document->type->label() }} · {{ $document->issue_date?->format('M j, Y') }}</span>
                        </span>
                        <span class="shrink-0 text-right">
                            <span class="tnum block text-[14.5px] font-bold text-ink">{{ Money::format($document->total, $document->currency) }}</span>
                            <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" />
                        </span>
                        <x-icon name="chevron-right" class="size-[16px] shrink-0 text-faint" stroke-width="2" />
                    </a>
                @empty
                    <p class="px-1.5 py-6 text-center text-[13.5px] text-muted">Nothing issued to this customer yet.</p>
                @endforelse
            </x-ui.panel>

            {{-- Payments --}}
            @if ($payments->isNotEmpty())
                <x-ui.panel title="Recent Payments" body-class="-mx-1.5">
                    @foreach ($payments as $index => $payment)
                        <div wire:key="pay-{{ $payment->id }}"
                             class="flex items-center gap-3 px-1.5 py-2.5 {{ $index > 0 ? 'border-t border-border' : '' }}">
                            <span class="min-w-0 flex-1">
                                <span class="block text-[14px] font-medium text-ink">{{ $payment->method->label() }}
                                    @if ($payment->receipt) <span class="text-muted">· {{ $payment->receipt->number }}</span> @endif
                                </span>
                                <span class="block text-[12.5px] text-muted">{{ $payment->received_at->format('M j, Y · g:ia') }}</span>
                            </span>
                            <span class="tnum shrink-0 text-[14.5px] font-semibold text-positive">
                                +{{ Money::format($payment->amount, $payment->currency) }}
                            </span>
                        </div>
                    @endforeach
                </x-ui.panel>
            @endif
        </div>

        {{-- Loyalty (start) --}}
        <div>
            @if ($loyaltyEnabled || $contact->hasLoyaltyCard())
                <x-ui.panel title="Loyalty">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="tnum text-[24px] font-bold tracking-[-0.02em] text-ink">{{ $contact->loyalty_points }}</p>
                            @php $companyForLoyalty = app(\App\Support\CurrentCompany::class)->get(); @endphp
                            <p class="text-[12.5px] text-muted">
                                points balance
                                @if ($companyForLoyalty && $companyForLoyalty->loyaltyPointValue() > 0)
                                    · worth {{ \App\Support\Money::format($contact->loyalty_points * $companyForLoyalty->loyaltyPointValue(), $companyForLoyalty->currency) }}
                                @endif
                            </p>
                        </div>
                        @if ($contact->hasLoyaltyCard())
                            <a href="{{ route('customers.loyalty-card.print', $contact) }}" target="_blank" rel="noopener"
                               class="focusable flex h-11 items-center gap-1.5 rounded-xl border border-border bg-surface px-3.5 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                <x-icon name="printer" class="size-[16px]" stroke-width="1.9" />
                                Print card
                            </a>
                        @else
                            @can('loyalty.manage')
                                <button type="button" wire:click="issueLoyaltyCard"
                                        class="tap focusable flex h-11 items-center gap-1.5 rounded-xl bg-fill-brand px-3.5 text-[13px] font-semibold text-white transition-opacity hover:opacity-90">
                                    Issue card
                                </button>
                            @endcan
                        @endif
                    </div>

                    @if ($contact->hasLoyaltyCard())
                        <p class="mt-1.5 text-[11.5px] text-faint">Card {{ $contact->loyalty_card_number }} · issued {{ $contact->loyalty_card_issued_at?->format('M j, Y') }}</p>
                    @endif

                    @can('loyalty.redeem')
                        <form wire:submit="redeemLoyaltyPoints" class="mt-4 flex items-end gap-2 border-t border-border pt-4">
                            <div class="min-w-0 flex-1">
                                <label for="redeem-points" class="mb-1 block text-[12px] font-semibold text-ink-2">Redeem points</label>
                                <input id="redeem-points" type="number" min="1" wire:model="redeemPoints" placeholder="e.g. 50"
                                       class="h-11 w-full rounded-lg border border-border bg-surface px-3 text-[13.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-1 focus:ring-brand/20">
                            </div>
                            <button type="submit"
                                    class="tap focusable h-11 shrink-0 rounded-lg border border-border bg-surface px-3.5 text-[13px] font-semibold text-ink-2 hover:bg-surface-2">
                                Redeem
                            </button>
                        </form>
                        @error('redeemPoints') <p class="mt-2 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror
                    @endcan

                    @if ($loyaltyTransactions->isNotEmpty())
                        <div class="mt-4 space-y-2 border-t border-border pt-4">
                            @foreach ($loyaltyTransactions as $tx)
                                <div wire:key="ltx-{{ $tx->id }}" class="flex items-center justify-between gap-3 text-[12.5px]">
                                    <span class="text-muted">{{ $tx->note ?? ucfirst($tx->type) }} · {{ $tx->created_at->format('M j') }}</span>
                                    <span class="tnum shrink-0 font-semibold {{ $tx->points > 0 ? 'text-positive' : 'text-warning' }}">
                                        {{ $tx->points > 0 ? '+' : '' }}{{ $tx->points }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-ui.panel>
            @endif
        </div>
        {{-- Loyalty (end) --}}

        {{-- Notes --}}
        <div>
            <x-ui.panel title="Notes">
                <div class="flex gap-2">
                    <input type="text" wire:model="note" wire:keydown.enter="addNote"
                           placeholder="Add a note…"
                           class="h-11 min-w-0 flex-1 rounded-xl border border-border bg-surface px-3.5 text-[14px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    <button type="button" wire:click="addNote"
                            class="tap focusable flex shrink-0 items-center justify-center rounded-xl bg-fill-brand px-4 text-white">
                        <x-icon name="plus" class="size-[18px]" stroke-width="2.4" />
                    </button>
                </div>
                @error('note') <p class="mt-2 text-[12.5px] font-medium text-warning">{{ $message }}</p> @enderror

                <div class="mt-4 space-y-3">
                    @forelse ($notes as $item)
                        <div wire:key="note-{{ $item->id }}" class="rounded-xl bg-surface-2 p-3.5">
                            <p class="text-[13.5px] leading-relaxed text-ink-2">{{ $item->body }}</p>
                            <p class="mt-1.5 text-[11.5px] text-faint">
                                {{ $item->user?->firstName() ?? 'System' }} · {{ $item->created_at->diffForHumans() }}
                            </p>
                        </div>
                    @empty
                        <p class="py-2 text-center text-[13px] text-muted">No notes yet.</p>
                    @endforelse
                </div>
            </x-ui.panel>
        </div>
    </div>
</div>
