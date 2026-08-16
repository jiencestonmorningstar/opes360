@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $tabClass = fn ($key) => $tab === $key
        ? 'rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white'
        : 'rounded-full px-4 py-2 text-[14px] font-semibold text-muted hover:text-ink';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Sourcing</h1>
            <p class="mt-1 text-[14.5px] text-muted">Ask several suppliers what it costs, then choose one answer and say why.</p>
        </div>

        @can('procurement.requisition-view')
            <a href="{{ route('procurement.requisitions') }}" wire:navigate
               class="tap focusable shrink-0 rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
                Requisitions
            </a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-2xl border border-emerald-300/60 bg-emerald-50 px-4 py-3 text-[14.5px] text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
            {{ session('status') }}
            @if (session('draftOrderId'))
                <a href="{{ route('documents.show', session('draftOrderId')) }}" wire:navigate class="font-semibold underline">
                    Open the draft order
                </a>
            @endif
        </div>
    @endif

    @error('award') <p class="mt-4 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror
    @error('opening') <p class="mt-4 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror

    <div class="mt-5 flex gap-1 border-b border-border pb-3">
        <button type="button" wire:click="$set('tab', 'rfqs')" class="{{ $tabClass('rfqs') }}">Out to suppliers</button>
        <button type="button" wire:click="$set('tab', 'approved')" class="{{ $tabClass('approved') }}">Approved and waiting</button>
    </div>

    {{-- ─────────────────────────────────────── approved, nothing asked yet ── --}}
    @if ($tab === 'approved')
        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Requisition</th>
                        <th class="px-4 py-3">Estimated</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($approved as $requisition)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $requisition->title }}
                                <span class="block text-[13px] font-normal text-muted">{{ $requisition->number }}</span>
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $currency }} {{ number_format((float) $requisition->estimated_total) }}</td>
                            <td class="px-4 py-3 text-right">
                                @can('procurement.rfq-manage')
                                    <button type="button" wire:click="openFor('{{ $requisition->id }}')"
                                            class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                        Ask suppliers
                                    </button>
                                @endcan
                            </td>
                        </tr>

                        @if ($opening === $requisition->id)
                            <tr class="border-t border-border bg-fill-2">
                                <td colspan="3" class="px-4 py-4">
                                    <div class="grid gap-3 sm:grid-cols-3">
                                        <div>
                                            <label class="{{ $labelClass }}">Title</label>
                                            <input type="text" wire:model="rfqTitle" class="{{ $inputClass }}">
                                            @error('rfqTitle') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                        </div>
                                        <div>
                                            <label class="{{ $labelClass }}">Answers by</label>
                                            <input type="date" wire:model="closesOn" class="{{ $inputClass }}">
                                        </div>
                                        <div>
                                            <label class="{{ $labelClass }}">Terms</label>
                                            <input type="text" wire:model="terms" placeholder="Delivered to site, 30 days" class="{{ $inputClass }}">
                                        </div>
                                    </div>

                                    <p class="mt-3 text-[13px] text-muted">
                                        The request carries the items and quantities, never a price. Quoting a figure would be telling
                                        suppliers the answer.
                                    </p>

                                    <div class="mt-3 flex gap-2">
                                        <button type="button" wire:click="openRfq"
                                                class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                            Open the request
                                        </button>
                                        <button type="button" wire:click="$set('opening', null)"
                                                class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                            Cancel
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-10 text-center text-muted">
                                Nothing approved is waiting. Requisitions appear here once an approver has agreed to them.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ─────────────────────────────────────────────────── the RFQ list ── --}}
    @if ($tab === 'rfqs')
        <div class="mt-5 overflow-hidden rounded-2xl border border-border">
            <table class="w-full text-left text-[14.5px]">
                <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                    <tr>
                        <th class="px-4 py-3">Request</th>
                        <th class="px-4 py-3">Asked</th>
                        <th class="px-4 py-3">Answered</th>
                        <th class="px-4 py-3">Status</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rfqs as $row)
                        <tr class="border-t border-border">
                            <td class="px-4 py-3 font-semibold text-ink">
                                {{ $row->title }}
                                <span class="block text-[13px] font-normal text-muted">{{ $row->number }}</span>
                            </td>
                            <td class="px-4 py-3 text-ink-2">{{ $row->invitations->count() }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $row->quotations->count() }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $statuses[$row->status] ?? $row->status }}</td>
                            <td class="px-4 py-3 text-right">
                                <button type="button" wire:click="select('{{ $row->id }}')"
                                        class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                    {{ $rfqId === $row->id ? 'Close' : 'Open' }}
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-muted">
                                No requests yet. Start one from an approved requisition.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rfq)
            {{-- ───────────────────────────────────────────── invitations ── --}}
            <div class="mt-6 rounded-2xl border border-border p-4">
                <p class="text-[15.5px] font-semibold text-ink">{{ $rfq->number }} — who was asked</p>

                <ul class="mt-2 space-y-1 text-[14px] text-ink-2">
                    @forelse ($rfq->invitations as $invitation)
                        <li>
                            {{ $invitation->supplier?->displayName() ?? 'Unknown supplier' }}
                            <span class="text-muted">
                                — {{ $invitation->responded_at ? 'replied' : 'no reply yet' }}
                            </span>
                        </li>
                    @empty
                        <li class="text-muted">Nobody yet.</li>
                    @endforelse
                </ul>

                @can('procurement.rfq-manage')
                    @if ($rfq->isOpen())
                        <div class="mt-4">
                            <label class="{{ $labelClass }}">Ask more suppliers</label>
                            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach ($suppliers as $supplier)
                                    <label class="flex items-center gap-2 text-[14px] text-ink-2">
                                        <input type="checkbox" wire:model="invitees" value="{{ $supplier->id }}" class="size-4 rounded border-border">
                                        {{ $supplier->displayName() }}
                                    </label>
                                @endforeach
                            </div>
                            @error('invitees') <p class="mt-2 text-[13px] text-rose-600">{{ $message }}</p> @enderror

                            <button type="button" wire:click="invite"
                                    class="tap focusable mt-3 rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                                Invite
                            </button>
                        </div>
                    @endif
                @endcan
            </div>

            {{-- ──────────────────────────────── recording what came back ── --}}
            @can('procurement.rfq-manage')
                @if ($rfq->isOpen())
                    <div class="mt-5">
                        @if (! $recording)
                            <button type="button" wire:click="startRecording"
                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                Record a quotation
                            </button>
                        @else
                            <div class="rounded-2xl border border-border p-4">
                                @error('quotation') <p class="mb-3 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror

                                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                    <div>
                                        <label class="{{ $labelClass }}">Supplier</label>
                                        <select wire:model="quotingSupplierId" class="{{ $inputClass }}">
                                            <option value="">Choose…</option>
                                            {{-- Only the invited. Somebody who was never asked cannot quote. --}}
                                            @foreach ($invited as $supplier)
                                                <option value="{{ $supplier->id }}">{{ $supplier->displayName() }}</option>
                                            @endforeach
                                        </select>
                                        @error('quotingSupplierId') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="{{ $labelClass }}">Their reference</label>
                                        <input type="text" wire:model="reference" placeholder="Their number" class="{{ $inputClass }}">
                                    </div>
                                    <div>
                                        <label class="{{ $labelClass }}">Quoted on</label>
                                        <input type="date" wire:model="quotedOn" class="{{ $inputClass }}">
                                    </div>
                                    <div>
                                        <label class="{{ $labelClass }}">Lead time (days)</label>
                                        <input type="number" wire:model="leadTimeDays" placeholder="0" class="{{ $inputClass }}">
                                    </div>
                                    <div>
                                        <label class="{{ $labelClass }}">Payment terms</label>
                                        <input type="text" wire:model="paymentTerms" placeholder="30 days" class="{{ $inputClass }}">
                                    </div>
                                </div>

                                <p class="mt-4 text-[13px] font-semibold uppercase tracking-wide text-muted">Their prices, against what we asked for</p>

                                <div class="mt-2 space-y-3">
                                    @foreach ($rfq->lines as $line)
                                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                            <div class="lg:col-span-2">
                                                <label class="{{ $labelClass }}">{{ $line->description }}</label>
                                                <p class="text-[13.5px] text-muted">
                                                    {{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }} {{ $line->unit }}
                                                </p>
                                            </div>
                                            <div>
                                                <label class="{{ $labelClass }}">Each</label>
                                                <input type="number" step="any" wire:model="prices.{{ $line->id }}.unit_price" class="{{ $inputClass }}">
                                            </div>
                                            <div>
                                                <label class="{{ $labelClass }}">Tax</label>
                                                <input type="number" step="any" wire:model="prices.{{ $line->id }}.tax_amount" class="{{ $inputClass }}">
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="mt-4 flex gap-2">
                                    <button type="button" wire:click="recordQuotation"
                                            class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                        Save the quotation
                                    </button>
                                    <button type="button" wire:click="$set('recording', false)"
                                            class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                                        Cancel
                                    </button>
                                </div>

                                <p class="mt-3 text-[13px] text-muted">
                                    This is their paper, transcribed. It keeps their reference and never enters our own numbering.
                                </p>
                            </div>
                        @endif
                    </div>
                @endif
            @endcan

            {{-- ───────────────────────────────────────────── comparison ── --}}
            <p class="mt-7 text-[13px] font-semibold uppercase tracking-wide text-muted">What they came back with</p>
            <p class="mt-1 text-[14px] text-muted">
                Ordered by price, and by price alone. Lead time and terms are here for you to weigh — deliberately not folded
                into one score, because a score that decides for you is one nobody can argue with.
            </p>

            <div class="mt-3 overflow-x-auto rounded-2xl border border-border">
                <table class="w-full text-left text-[14.5px]">
                    <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                        <tr>
                            <th class="px-4 py-3">Cheapest first</th>
                            <th class="px-4 py-3">Supplier</th>
                            <th class="px-4 py-3">Total</th>
                            <th class="px-4 py-3">Against cheapest</th>
                            <th class="px-4 py-3">Lead time</th>
                            <th class="px-4 py-3">Terms</th>
                            <th class="px-4 py-3">Valid until</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $cheapest = (float) ($comparison->first()?->total ?? 0); @endphp

                        @forelse ($comparison as $rank => $quotation)
                            <tr class="border-t border-border {{ $quotation->status === 'awarded' ? 'bg-emerald-50/60 dark:bg-emerald-500/10' : '' }}">
                                <td class="px-4 py-3 font-semibold text-ink">{{ $rank + 1 }}</td>
                                <td class="px-4 py-3 text-ink">
                                    {{ $quotation->supplier?->displayName() ?? '—' }}
                                    @if ($quotation->reference)
                                        <span class="block text-[13px] text-muted">their ref {{ $quotation->reference }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 font-semibold text-ink">
                                    {{ $currency }} {{ number_format((float) $quotation->total) }}
                                </td>
                                <td class="px-4 py-3 text-ink-2">
                                    @if ($rank === 0)
                                        —
                                    @else
                                        +{{ $currency }} {{ number_format((float) $quotation->total - $cheapest) }}
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-ink-2">
                                    {{ $quotation->lead_time_days === null ? 'Not said' : $quotation->lead_time_days.' days' }}
                                </td>
                                <td class="px-4 py-3 text-ink-2">{{ $quotation->payment_terms ?: 'Not said' }}</td>
                                <td class="px-4 py-3 {{ $quotation->hasLapsed() ? 'font-semibold text-rose-600' : 'text-ink-2' }}">
                                    {{ $quotation->valid_until?->toFormattedDateString() ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if ($quotation->status === 'awarded')
                                        <span class="text-[13.5px] font-semibold text-emerald-700 dark:text-emerald-300">Chosen</span>
                                    @elseif ($rfq->isOpen())
                                        @can('procurement.rfq-award')
                                            <button type="button" wire:click="award('{{ $quotation->id }}')"
                                                    class="tap focusable rounded-full bg-fill-brand px-4 py-1.5 text-[13.5px] font-semibold text-white">
                                                Award
                                            </button>
                                        @endcan
                                    @else
                                        <span class="text-[13.5px] text-muted">Not chosen</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-4 py-10 text-center text-muted">
                                    Nobody has answered yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($rfq->purchaseOrder)
                <div class="mt-4 rounded-2xl border border-border bg-fill-2 px-4 py-3 text-[14.5px] text-ink-2">
                    Awarded. A <strong>draft</strong> purchase order was raised and is not yet numbered or issued —
                    awarding is the sourcing decision, issuing is the commitment.
                    <a href="{{ route('documents.show', $rfq->purchaseOrder) }}" wire:navigate class="font-semibold underline">
                        Check it and issue it
                    </a>
                </div>
            @endif
        @endif
    @endif
</div>
