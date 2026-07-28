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
                        <div class="flex items-center gap-3.5 px-1 py-3 {{ ! $loop->first ? 'border-t border-border' : '' }}"
                             wire:key="pay-{{ $allocation->id }}">
                            <span class="flex size-[38px] shrink-0 items-center justify-center rounded-full bg-tint-green">
                                <x-icon name="banknotes" class="size-[19px] text-accent-green" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[14px] font-medium text-ink">
                                    {{ $allocation->payment->method->label() }}
                                    @if ($allocation->payment->receipt)
                                        <span class="text-muted">· {{ $allocation->payment->receipt->number }}</span>
                                    @endif
                                </p>
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
                @if (session('documentError'))
                    <div class="mb-3 rounded-xl bg-tint-orange px-4 py-2.5 text-[13px] font-semibold text-warning">
                        {{ session('documentError') }}
                    </div>
                @endif

                <div class="space-y-2.5">
                    <a href="{{ route('documents.print', $document) }}" target="_blank"
                       class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-brand text-[14px] font-semibold text-white transition-opacity hover:opacity-90">
                        <x-icon name="document" class="size-[18px]" stroke-width="2" />
                        Print / PDF
                    </a>

                    @if ((float) $document->balance > 0 && ! in_array($document->status->value, ['draft', 'void'], true) && auth()->user()->can('record', \App\Models\Payment::class))
                        <button type="button" wire:click="openPayment"
                                class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                            <x-icon name="banknotes" class="size-[18px]" stroke-width="2" />
                            Record Payment
                        </button>
                    @endif

                    @if ($canConvert && $convertTarget && auth()->user()->can('convert', $document))
                        <button type="button" wire:click="convert" wire:loading.attr="disabled"
                                class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                            <x-icon name="document-plus" class="size-[18px]" stroke-width="2" />
                            Convert to {{ $convertTarget->label() }}
                        </button>
                    @endif

                    @if ($document->verificationToken)
                        <a href="{{ route('verification.show', $document->verificationToken->token) }}" target="_blank"
                           class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-surface-2 text-[14px] font-semibold text-ink-2 transition-colors hover:bg-tint-blue hover:text-brand">
                            <x-icon name="qr-code" class="size-[18px]" stroke-width="2" />
                            Share
                        </a>
                    @endif

                    @if ($document->status->value !== 'void' && auth()->user()->can('void', $document))
                        <button type="button" wire:click="openVoid"
                                class="focusable flex h-11 w-full items-center justify-center gap-2 rounded-xl text-[14px] font-semibold text-negative transition-colors hover:bg-tint-orange">
                            <x-icon name="alert" class="size-[18px]" stroke-width="2" />
                            Void {{ $document->type->label() }}
                        </button>
                    @endif
                </div>
            </x-ui.panel>

            {{-- Void confirmation, revealed in place --}}
            @if ($voidingOpen)
                <x-ui.panel title="Void this document?">
                    <x-slot:actions>
                        <button type="button" wire:click="closeVoid"
                                class="focusable text-[14px] font-semibold text-muted hover:text-ink">Cancel</button>
                    </x-slot:actions>

                    <p class="text-[13.5px] leading-relaxed text-muted">
                        The number is retired, not reused, and anyone scanning the printed copy will see
                        <span class="font-semibold text-ink-2">Voided</span>. This cannot be undone —
                        issue a replacement instead.
                    </p>

                    <label class="mt-4 block">
                        <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Reason <span class="font-normal text-faint">(optional)</span></span>
                        <input type="text" wire:model="voidReason" placeholder="Duplicate, cancelled order…"
                               class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </label>
                    @error('voidReason')
                        <p class="mt-2 text-[13px] font-medium text-warning">{{ $message }}</p>
                    @enderror

                    <button type="button" wire:click="voidDocument" wire:loading.attr="disabled"
                            class="focusable mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-negative text-[14.5px] font-semibold text-white hover:opacity-90">
                        <span wire:loading.remove wire:target="voidDocument">Yes, void it</span>
                        <span wire:loading wire:target="voidDocument">Voiding…</span>
                    </button>
                </x-ui.panel>
            @endif

            {{-- Record-payment panel, revealed in place --}}
            @if ($payingOpen)
                <x-ui.panel title="Record Payment">
                    <x-slot:actions>
                        <button type="button" wire:click="closePayment"
                                class="focusable text-[14px] font-semibold text-muted hover:text-ink">
                            Cancel
                        </button>
                    </x-slot:actions>

                    <label class="block">
                        <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Amount</span>
                        <input type="number" step="any" min="0" inputmode="decimal" wire:model="amount"
                               class="tnum h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[16px] font-semibold text-ink focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </label>
                    @error('amount')
                        <p class="mt-2 text-[13px] font-medium text-warning">{{ $message }}</p>
                    @enderror

                    <div class="mt-4">
                        <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Method</span>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach (\App\Enums\PaymentMethod::cases() as $payMethod)
                                <button type="button" wire:click="$set('method', '{{ $payMethod->value }}')"
                                        class="focusable h-11 rounded-xl text-[13.5px] font-semibold transition-colors
                                               {{ $method === $payMethod->value
                                                   ? 'bg-tint-blue text-brand ring-1 ring-brand/40'
                                                   : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                                    {{ $payMethod->label() }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <label class="mt-4 block">
                        <span class="mb-1.5 block text-[13px] font-semibold text-ink-2">Reference <span class="font-normal text-faint">(optional)</span></span>
                        <input type="text" wire:model="reference" placeholder="Transfer ref, transaction id…"
                               class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[14.5px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    </label>

                    <button type="button" wire:click="recordPayment" wire:loading.attr="disabled"
                            class="focusable mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-brand text-[14.5px] font-semibold text-white transition-opacity hover:opacity-90">
                        <span wire:loading.remove wire:target="recordPayment">Confirm &amp; Issue Receipt</span>
                        <span wire:loading wire:target="recordPayment">Recording…</span>
                    </button>
                </x-ui.panel>
            @endif

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
                            <p class="truncate text-[12px] text-muted">Anyone scanning this code sees the live verdict.</p>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-4 rounded-xl border border-border p-3.5">
                        {{-- White plate behind the QR in both themes: scanners need contrast. --}}
                        <img src="{{ route('verification.qr', $document->verificationToken->token) }}"
                             alt="Verification QR code"
                             class="size-[92px] shrink-0 rounded-lg bg-white p-1.5">
                        <div class="min-w-0">
                            <p class="text-[12.5px] font-medium text-muted">Public verification link</p>
                            <p class="mt-0.5 break-all text-[12.5px] font-semibold text-ink">
                                {{ $document->verificationToken->publicUrl() }}
                            </p>
                            <a href="{{ route('verification.show', $document->verificationToken->token) }}" target="_blank"
                               class="focusable mt-2 inline-flex items-center gap-1 text-[13px] font-semibold text-brand hover:underline">
                                Open verification page
                                <x-icon name="chevron-right" class="size-[14px]" stroke-width="2.4" />
                            </a>
                        </div>
                    </div>
                @else
                    <p class="text-[13.5px] text-muted">A verification QR is attached when the document is issued.</p>
                @endif
            </x-ui.panel>
        </div>
    </div>
</div>
