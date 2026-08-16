@php
    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
    $tabClass = fn ($key) => $filter === $key
        ? 'rounded-full bg-fill-brand px-4 py-2 text-[14px] font-semibold text-white'
        : 'rounded-full px-4 py-2 text-[14px] font-semibold text-muted hover:text-ink';

    // Returned and rejected are answers of different kinds, so they are never
    // allowed to look alike: amber is "not like that", rose is "no".
    $chipClass = fn ($status) => match ($status) {
        'approved' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'returned' => 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200',
        'rejected', 'cancelled' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300',
        'submitted' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        default => 'bg-fill-2 text-ink-2',
    };
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-center justify-between gap-4">
        <div class="min-w-0">
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Requisitions</h1>
            <p class="mt-1 text-[14.5px] text-muted">Ask for something. Somebody else agrees to it before any money is committed.</p>
        </div>

        @can('procurement.rfq-view')
            <a href="{{ route('procurement.sourcing') }}" wire:navigate
               class="tap focusable shrink-0 rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
                Sourcing
            </a>
        @endcan
    </div>

    @if (session('status'))
        <div class="mt-5 rounded-2xl border border-emerald-300/60 bg-emerald-50 px-4 py-3 text-[14.5px] text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
            {{ session('status') }}
        </div>
    @endif

    @error('form') <p class="mt-4 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror
    @error('submit') <p class="mt-4 text-[14px] font-semibold text-rose-600">{{ $message }}</p> @enderror

    <div class="mt-5 flex flex-wrap items-center gap-1 border-b border-border pb-3">
        <button type="button" wire:click="$set('filter', 'open')" class="{{ $tabClass('open') }}">Still ours</button>
        <button type="button" wire:click="$set('filter', 'waiting')" class="{{ $tabClass('waiting') }}">Awaiting approval</button>
        <button type="button" wire:click="$set('filter', 'decided')" class="{{ $tabClass('decided') }}">Decided</button>
        <button type="button" wire:click="$set('filter', 'all')" class="{{ $tabClass('all') }}">Everything</button>
    </div>

    {{-- ──────────────────────────────────────────────── raise a new one ── --}}
    @can('procurement.requisition-manage')
        <div class="mt-5">
            @if (! $raising)
                <button type="button" wire:click="startRaising"
                        class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                    Raise a requisition
                </button>
            @else
                <div class="rounded-2xl border border-border p-4">
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="lg:col-span-2">
                            <label class="{{ $labelClass }}">What for</label>
                            <input type="text" wire:model="title" placeholder="Site generator spares" class="{{ $inputClass }}">
                            @error('title') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Needed by</label>
                            <input type="date" wire:model="neededBy" class="{{ $inputClass }}">
                        </div>
                        <div>
                            <label class="{{ $labelClass }}">Cost centre</label>
                            <select wire:model="costCentreId" class="{{ $inputClass }}">
                                <option value="">Not allocated</option>
                                @foreach ($costCentres as $centre)
                                    <option value="{{ $centre->id }}">{{ $centre->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="{{ $labelClass }}">Why it is needed</label>
                        <textarea wire:model="justification" rows="2"
                                  placeholder="The standby generator will not start."
                                  class="w-full rounded-xl border border-border bg-surface px-3.5 py-3 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20"></textarea>
                        <p class="mt-1 text-[13px] text-muted">The approver reads this and nothing else. Say what happens if it is not bought.</p>
                    </div>

                    <p class="mt-5 text-[13px] font-semibold uppercase tracking-wide text-muted">What is being asked for</p>

                    <div class="mt-2 space-y-3">
                        @foreach ($lines as $index => $line)
                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-12">
                                <div class="lg:col-span-5">
                                    <label class="{{ $labelClass }}">Item</label>
                                    <input type="text" wire:model="lines.{{ $index }}.description"
                                           placeholder="Fuel filter" class="{{ $inputClass }}">
                                    @error('lines.'.$index.'.description') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="{{ $labelClass }}">How many</label>
                                    <input type="number" step="any" wire:model="lines.{{ $index }}.quantity" class="{{ $inputClass }}">
                                    @error('lines.'.$index.'.quantity') <p class="mt-1 text-[13px] text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="{{ $labelClass }}">Unit</label>
                                    <input type="text" wire:model="lines.{{ $index }}.unit" placeholder="unit" class="{{ $inputClass }}">
                                </div>
                                <div class="lg:col-span-2">
                                    <label class="{{ $labelClass }}">Estimated each</label>
                                    <input type="number" step="any" wire:model="lines.{{ $index }}.estimatedUnitPrice"
                                           placeholder="0" class="{{ $inputClass }}">
                                </div>
                                <div class="flex items-end lg:col-span-1">
                                    <button type="button" wire:click="removeLine({{ $index }})"
                                            class="tap focusable h-12 w-full rounded-xl border border-border text-[13.5px] font-semibold text-muted">
                                        Remove
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <button type="button" wire:click="addLine"
                            class="tap focusable mt-3 rounded-full border border-border px-4 py-2 text-[13.5px] font-semibold text-ink-2">
                        Add another line
                    </button>

                    <p class="mt-3 text-[13px] text-muted">
                        Prices here are estimates, not quotes. Suppliers are asked what it really costs after this is approved.
                    </p>

                    <div class="mt-4 flex gap-2">
                        <button type="button" wire:click="save"
                                class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                            Save as draft
                        </button>
                        <button type="button" wire:click="cancel"
                                class="tap focusable rounded-full border border-border px-5 py-2 text-[14.5px] font-semibold text-ink-2">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endcan

    {{-- ───────────────────────────────────────────────────────── the list ── --}}
    <div class="mt-5 overflow-hidden rounded-2xl border border-border">
        <table class="w-full text-left text-[14.5px]">
            <thead class="bg-fill-2 text-[13px] font-semibold text-ink-2">
                <tr>
                    <th class="px-4 py-3">Requisition</th>
                    <th class="px-4 py-3">Estimated</th>
                    <th class="px-4 py-3">Where it is</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($requisitions as $requisition)
                    <tr class="border-t border-border">
                        <td class="px-4 py-3 font-semibold text-ink">
                            {{ $requisition->title }}
                            <span class="block text-[13px] font-normal text-muted">
                                {{ $requisition->number }}
                                @if ($requisition->creator), asked for by {{ $requisition->creator->name }} @endif
                            </span>
                        </td>
                        <td class="px-4 py-3 text-ink-2">
                            {{ $currency }} {{ number_format((float) $requisition->estimated_total) }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="rounded-full px-2.5 py-1 text-[13px] font-semibold {{ $chipClass($requisition->status) }}">
                                {{ $statuses[$requisition->status] ?? $requisition->status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" wire:click="view('{{ $requisition->id }}')"
                                    class="tap focusable rounded-full border border-border px-3.5 py-1.5 text-[13.5px] font-semibold text-ink-2">
                                {{ $viewing === $requisition->id ? 'Close' : 'Open' }}
                            </button>
                        </td>
                    </tr>

                    @if ($viewing === $requisition->id)
                        <tr class="border-t border-border bg-fill-2">
                            <td colspan="4" class="px-4 py-4">
                                @if ($requisition->status === 'returned')
                                    <div class="mb-4 rounded-2xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-[14.5px] text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
                                        <strong>Changes were asked for.</strong>
                                        This is not a refusal — it came back because it is not wanted in this shape.
                                        The reason is on the request in your <a href="{{ route('actions') }}" class="underline">approvals</a>.
                                        Put it right and submit it again.
                                    </div>
                                @elseif ($requisition->status === 'rejected')
                                    <div class="mb-4 rounded-2xl border border-rose-300/60 bg-rose-50 px-4 py-3 text-[14.5px] text-rose-900 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
                                        <strong>This was refused.</strong>
                                        The answer was no, not "not like that". Raise a fresh requisition if the need has changed.
                                    </div>
                                @elseif ($requisition->status === 'submitted')
                                    <div class="mb-4 rounded-2xl border border-border bg-surface px-4 py-3 text-[14.5px] text-ink-2">
                                        With its approver. The decision is taken in the
                                        <a href="{{ route('actions') }}" class="underline">approvals inbox</a>
                                        by whoever the workflow asks — nobody decides it from this screen.
                                    </div>
                                @endif

                                @if ($requisition->justification)
                                    <p class="mb-3 text-[14px] text-ink-2"><span class="font-semibold">Why:</span> {{ $requisition->justification }}</p>
                                @endif

                                <table class="w-full text-left text-[14px]">
                                    <thead class="text-[13px] font-semibold text-muted">
                                        <tr>
                                            <th class="py-1.5">Item</th>
                                            <th class="py-1.5">Quantity</th>
                                            <th class="py-1.5">Estimated each</th>
                                            <th class="py-1.5">Estimated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($requisition->lines as $line)
                                            <tr class="border-t border-border">
                                                <td class="py-2 text-ink">{{ $line->description }}</td>
                                                <td class="py-2 text-ink-2">{{ rtrim(rtrim(number_format((float) $line->quantity, 3), '0'), '.') }} {{ $line->unit }}</td>
                                                <td class="py-2 text-ink-2">{{ number_format((float) $line->estimated_unit_price) }}</td>
                                                <td class="py-2 text-ink-2">{{ number_format((float) $line->estimated_total) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>

                                <div class="mt-4 flex flex-wrap items-center gap-2">
                                    @can('procurement.requisition-manage')
                                        @if ($requisition->isOpen())
                                            <button type="button" wire:click="submit('{{ $requisition->id }}')"
                                                    class="tap focusable rounded-full bg-fill-brand px-5 py-2 text-[14.5px] font-semibold text-white">
                                                {{ $requisition->status === 'returned' ? 'Submit again' : 'Submit for approval' }}
                                            </button>
                                        @endif
                                    @endcan

                                    @if ($requisition->purchaseOrder)
                                        <a href="{{ route('documents.show', $requisition->purchaseOrder) }}" wire:navigate
                                           class="tap focusable rounded-full border border-border px-4 py-2 text-[14px] font-semibold text-ink-2">
                                            The order this became
                                        </a>
                                    @endif

                                    @if ($requisition->needed_by)
                                        <span class="text-[13.5px] text-muted">Needed by {{ $requisition->needed_by->toFormattedDateString() }}</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-muted">
                            Nothing here. A requisition is how you ask for something before anyone commits to buying it.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
