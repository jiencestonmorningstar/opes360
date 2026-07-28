@php
    use App\Support\Accent;
    use App\Support\Money;

    $state = $document->paymentState();
    $accent = $document->contact?->accent() ?? 'blue';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    {{-- Back + header --}}
    <div class="flex items-center gap-3">
        <a href="{{ route('sales', ['type' => $document->type->value]) }}"
           class="tap focusable -ml-2 flex items-center justify-center rounded-lg text-muted hover:text-ink"
           aria-label="Back to sales">
            <x-icon name="chevron-left" class="size-[22px]" stroke-width="2.2" />
        </a>

        <div class="min-w-0 flex-1">
            <h1 class="truncate text-[22px] font-bold leading-tight tracking-[-0.02em] text-ink lg:text-[25px]">
                {{ $document->number ?? 'Draft '.$document->type->label() }}
            </h1>
            <p class="mt-0.5 text-[13.5px] text-muted">
                {{ $document->type->label() }} · Issued {{ $document->issue_date?->format('M j, Y') ?? '—' }}
            </p>
        </div>

        <x-ui.status-badge :label="$state['label']" :tone="$state['tone']" class="shrink-0 !text-[13.5px]" />
    </div>

    <div class="mt-5 grid gap-4 lg:grid-cols-3">

        {{-- Main column: parties + lines + totals --}}
        <div class="space-y-4 lg:col-span-2">

            <x-ui.panel title="Billed To">
                <div class="flex items-center gap-3.5">
                    <span class="flex size-[46px] shrink-0 items-center justify-center rounded-full {{ Accent::tint($accent) }} text-[14px] font-bold {{ Accent::text($accent) }}">
                        {{ $document->contact?->initials() ?? '—' }}
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-[15.5px] font-semibold text-ink">
                            {{ $document->contact?->displayName() ?? 'Walk-in customer' }}
                        </p>
                        <p class="truncate text-[13px] text-muted">
                            {{ $document->contact?->email ?? data_get($document->contact?->phones, 0, '') }}
                        </p>
                    </div>
                    @if ($document->due_date)
                        <div class="ml-auto shrink-0 text-right">
                            <p class="text-[11.5px] font-medium uppercase tracking-wide text-faint">Due</p>
                            <p class="text-[13.5px] font-semibold text-ink">{{ $document->due_date->format('M j, Y') }}</p>
                        </div>
                    @endif
                </div>
            </x-ui.panel>

            <x-ui.panel title="Items" body-class="-mx-1">
                @foreach ($document->lines as $line)
                    <div class="flex items-start justify-between gap-4 px-1 py-3 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                        <div class="min-w-0">
                            <p class="text-[14.5px] font-medium text-ink">{{ $line->description }}</p>
                            <p class="tnum mt-0.5 text-[12.5px] text-muted">
                                {{ rtrim(rtrim($line->quantity, '0'), '.') }} {{ $line->unit }}
                                × {{ Money::format($line->unit_price, $document->currency) }}
                            </p>
                        </div>
                        <p class="tnum shrink-0 text-[14.5px] font-semibold text-ink">
                            {{ Money::format($line->line_total, $document->currency) }}
                        </p>
                    </div>
                @endforeach

                <div class="mt-1 space-y-2 border-t border-border px-1 pt-3.5">
                    <div class="flex justify-between text-[13.5px] text-muted">
                        <span>Subtotal</span>
                        <span class="tnum">{{ Money::format($document->subtotal, $document->currency) }}</span>
                    </div>
                    @if ((float) $document->discount_total > 0)
                        <div class="flex justify-between text-[13.5px] text-muted">
                            <span>Discount</span>
                            <span class="tnum">−{{ Money::format($document->discount_total, $document->currency) }}</span>
                        </div>
                    @endif
                    @if ((float) $document->tax_total > 0)
                        <div class="flex justify-between text-[13.5px] text-muted">
                            <span>Tax</span>
                            <span class="tnum">{{ Money::format($document->tax_total, $document->currency) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between pt-1 text-[17px] font-bold text-ink">
                        <span>Total</span>
                        <span class="tnum">{{ Money::format($document->total, $document->currency) }}</span>
                    </div>
                    @if ((float) $document->amount_paid > 0)
                        <div class="flex justify-between text-[13.5px] font-medium text-positive">
                            <span>Paid</span>
                            <span class="tnum">−{{ Money::format($document->amount_paid, $document->currency) }}</span>
                        </div>
                        <div class="flex justify-between text-[15px] font-bold {{ (float) $document->balance > 0 ? 'text-warning' : 'text-positive' }}">
                            <span>Balance</span>
                            <span class="tnum">{{ Money::format($document->balance, $document->currency) }}</span>
                        </div>
                    @endif
                </div>
            </x-ui.panel>

            @if ($document->allocations->isNotEmpty())
                <x-ui.panel title="Payments" body-class="-mx-1">
                    @foreach ($document->allocations as $allocation)
                        <div class="flex items-center gap-3.5 px-1 py-3 {{ ! $loop->first ? 'border-t border-border' : '' }}">
                            <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full bg-tint-green">
                                <x-icon name="banknotes" class="size-[19px] text-accent-green" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[14px] font-medium text-ink">{{ $allocation->payment->method->label() }}</p>
                                <p class="text-[12.5px] text-muted">{{ $allocation->payment->received_at->format('M j, Y · g:ia') }}</p>
                            </div>
                            <p class="tnum shrink-0 text-[14.5px] font-semibold text-positive">
                                {{ Money::format($allocation->amount, $document->currency) }}
                            </p>
                        </div>
                    @endforeach
                </x-ui.panel>
            @endif
        </div>

        {{-- Side column: actions + verification --}}
        <div class="space-y-4">
            <x-ui.panel title="Actions">
                <div class="space-y-2.5">
                    @foreach ([
                        ['label' => 'Download PDF', 'icon' => 'document', 'primary' => true],
                        ['label' => 'Record Payment', 'icon' => 'banknotes', 'primary' => false],
                        ['label' => 'Share', 'icon' => 'qr-code', 'primary' => false],
                    ] as $action)
                        <button type="button"
                                class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl text-[14px] font-semibold transition-colors
                                       {{ $action['primary']
                                           ? 'bg-brand text-white hover:opacity-90'
                                           : 'bg-surface-2 text-ink-2 hover:bg-tint-blue hover:text-brand' }}">
                            <x-icon :name="$action['icon']" class="size-[18px]" stroke-width="2" />
                            {{ $action['label'] }}
                        </button>
                    @endforeach
                </div>
            </x-ui.panel>

            <x-ui.panel title="Verification">
                @if ($document->verificationToken)
                    <div class="flex items-center gap-3">
                        <span class="flex size-[42px] shrink-0 items-center justify-center rounded-xl {{ $document->isTampered() ? 'bg-tint-orange' : 'bg-tint-green' }}">
                            <x-icon :name="$document->isTampered() ? 'alert' : 'check-circle'"
                                    class="size-[22px] {{ $document->isTampered() ? 'text-warning' : 'text-accent-green' }}" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[14px] font-semibold {{ $document->isTampered() ? 'text-warning' : 'text-positive' }}">
                                {{ $document->isTampered() ? 'Content mismatch' : 'Verified authentic' }}
                            </p>
                            <p class="truncate text-[12px] text-muted">Scan the QR on the printed copy to confirm.</p>
                        </div>
                    </div>
                @else
                    <p class="text-[13.5px] text-muted">A verification QR is attached when the document is issued.</p>
                @endif
            </x-ui.panel>
        </div>
    </div>
</div>
