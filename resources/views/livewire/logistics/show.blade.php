<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('logistics') }}" wire:navigate class="text-[13.5px] font-semibold text-muted hover:text-ink">&larr; Dispatch</a>
            <h1 class="mt-1 text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">{{ $shipment->reference }}</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $shipment->cargo_description }} · {{ $shipment->from_location }} → {{ $shipment->to_location }}
            </p>
            <p class="mt-0.5 text-[13.5px] text-faint">
                {{ $shipment->sender?->name }} → {{ $shipment->receiver?->name }}
                @if ($shipment->weight_kg !== null) · {{ $shipment->weight_kg }} kg @endif
            </p>
        </div>

        <span @class([
            'shrink-0 rounded-full px-4 py-1.5 text-[13.5px] font-semibold',
            'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' => $shipment->inException(),
            'bg-surface-2 text-ink' => ! $shipment->inException(),
        ])>
            {{ $shipment->statusLabel() }}
        </span>
    </div>

    @error('shipment')
        <div class="mt-4 rounded-xl border border-rose-300 bg-rose-50 px-4 py-3 text-[14px] text-rose-800 dark:border-rose-500/50 dark:bg-rose-500/10 dark:text-rose-200">
            {{ $message }}
        </div>
    @enderror

    @can('logistics.manage')
        <div class="mt-5 flex flex-wrap items-center gap-3">
            @if ($shipment->status === 'in_transit')
                <label class="flex items-center gap-2 text-[13.5px] text-ink-2">
                    <input type="checkbox" wire:model="withPod" class="rounded border-border">
                    Ask the receiver to sign a proof of delivery
                </label>
                <button type="button" wire:click="deliver"
                        class="tap focusable rounded-full bg-fill-brand px-5 py-2.5 text-[14.5px] font-semibold text-white">
                    Mark delivered
                </button>
                <button type="button" wire:click="$toggle('failing')"
                        class="tap focusable rounded-full border border-rose-300 px-5 py-2.5 text-[14.5px] font-semibold text-rose-600">
                    Delivery failed…
                </button>
            @endif

            @if ($shipment->inException())
                <button type="button" wire:click="retry"
                        class="tap focusable rounded-full bg-fill-brand px-5 py-2.5 text-[14.5px] font-semibold text-white">
                    Retry delivery
                </button>
                <button type="button" wire:click="returnToSender"
                        wire:confirm="Return {{ $shipment->reference }} to {{ $shipment->sender?->name }}? This ends the shipment."
                        class="tap focusable rounded-full border border-border px-5 py-2.5 text-[14.5px] font-semibold text-ink">
                    Return to sender
                </button>
            @endif

            @if ($shipment->status === 'booked')
                <button type="button" wire:click="cancel" wire:confirm="Cancel this booking?"
                        class="tap focusable rounded-full border border-border px-5 py-2.5 text-[14.5px] font-semibold text-ink">
                    Cancel booking
                </button>
            @endif

            @if (! $shipment->isInvoiced() && $shipment->freight_amount !== null && $shipment->status !== 'cancelled')
                <button type="button" wire:click="invoice"
                        class="tap focusable rounded-full border border-border px-5 py-2.5 text-[14.5px] font-semibold text-ink">
                    Draft freight invoice
                </button>
            @endif

            @if (Route::has('logistics.waybill.print'))
                <a href="{{ route('logistics.waybill.print', $shipment) }}" target="_blank"
                   class="tap focusable rounded-full border border-border px-5 py-2.5 text-[14.5px] font-semibold text-ink">
                    Print waybill
                </a>
            @endif
        </div>

        {{-- The failed-attempt strip: the reason is required, in words the
             tracking page will repeat to the customer. --}}
        @if ($failing)
            <form wire:submit="fail" class="card mt-4 flex flex-wrap items-end gap-3 p-4">
                <div class="min-w-64 grow">
                    <label class="mb-1.5 block text-[13px] font-semibold text-ink-2">Why did the delivery fail?</label>
                    <input type="text" wire:model="failReason" placeholder="Absent receiver, cargo refused…"
                           class="h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20">
                    @error('failReason') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="tap focusable rounded-full bg-rose-600 px-5 py-2.5 text-[14.5px] font-semibold text-white">
                    Record failed attempt
                </button>
            </form>
        @endif
    @endcan

    <div class="mt-6 grid gap-5 lg:grid-cols-3">

        {{-- ── History — the same story the public tracking page tells ──── --}}
        <section class="card p-5 lg:col-span-2">
            <h2 class="text-[15px] font-bold text-ink">History</h2>
            <ol class="mt-3 space-y-3">
                @forelse ($shipment->events as $event)
                    <li wire:key="ev-{{ $event->id }}" class="flex items-baseline justify-between gap-4">
                        <div>
                            <p class="text-[14px] font-semibold text-ink">{{ $event->statusLabel() }}</p>
                            @if ($event->note)
                                <p class="text-[13px] text-muted">{{ $event->note }}</p>
                            @endif
                        </div>
                        <p class="shrink-0 text-[12.5px] text-faint">{{ $event->happened_at->format('d/m/Y H:i') }}</p>
                    </li>
                @empty
                    <li class="text-[13.5px] text-muted">No movements recorded yet.</li>
                @endforelse
            </ol>

            <div class="mt-5 border-t border-line pt-4">
                <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Public tracking link</p>
                <p class="mt-1 break-all text-[13.5px] text-muted">{{ url('/track/'.$shipment->tracking_token) }}</p>
            </div>
        </section>

        {{-- ── The links out: POD and invoice, never copies ─────────────── --}}
        <section class="space-y-5">
            <div class="card p-5">
                <h2 class="text-[15px] font-bold text-ink">Proof of delivery</h2>
                @if ($shipment->podDocument === null)
                    <p class="mt-2 text-[13.5px] text-muted">
                        {{ $shipment->status === 'delivered' ? 'Delivered without a signature.' : 'Requested at delivery, if wanted.' }}
                    </p>
                @else
                    <p class="mt-2 text-[13.5px] text-muted">
                        {{ $shipment->podDocument->title }} —
                        <span class="font-semibold text-ink">{{ str_replace('_', ' ', $podStatus['status']) }}</span>
                        ({{ $podStatus['signed'] }}/{{ $podStatus['total'] }} signed)
                    </p>
                    <a href="{{ route('papers.show', $shipment->podDocument) }}" wire:navigate
                       class="mt-2 inline-block text-[13.5px] font-semibold text-brand underline-offset-2 hover:underline">
                        Open the paper
                    </a>
                @endif
            </div>

            <div class="card p-5">
                <h2 class="text-[15px] font-bold text-ink">Freight invoice</h2>
                @if ($shipment->invoice === null)
                    <p class="mt-2 text-[13.5px] text-muted">
                        @if ($shipment->freight_amount === null)
                            No freight amount recorded.
                        @else
                            {{ number_format((float) $shipment->freight_amount) }} — not yet invoiced.
                        @endif
                    </p>
                @else
                    <p class="mt-2 text-[13.5px] text-muted">
                        Invoice {{ $shipment->invoice->number ?? '(draft)' }} ·
                        {{ number_format((float) $shipment->invoice->total) }} {{ $shipment->invoice->currency }}
                    </p>
                    <a href="{{ route('documents.show', $shipment->invoice) }}" wire:navigate
                       class="mt-2 inline-block text-[13.5px] font-semibold text-brand underline-offset-2 hover:underline">
                        Open the invoice
                    </a>
                @endif
            </div>

            @if ($openManifest !== null)
                <div class="card p-5">
                    <h2 class="text-[15px] font-bold text-ink">Aboard</h2>
                    <p class="mt-2 text-[13.5px] text-muted">
                        {{ $openManifest->reference }} — departs {{ $openManifest->departs_on->toFormattedDateString() }}
                    </p>
                </div>
            @endif
        </section>
    </div>
</div>
