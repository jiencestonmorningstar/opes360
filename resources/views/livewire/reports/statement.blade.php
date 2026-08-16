@php
    use App\Support\Aging;
    use App\Support\Money;
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Statement</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                Everything that passed between you and one customer, and what is left at the end of it.
            </p>
        </div>

        @can('reports.export')
            @if ($report)
                <button type="button" wire:click="export"
                        class="tap focusable flex shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-5 text-[14.5px] font-semibold text-ink transition-colors hover:bg-surface-2">
                    <x-icon name="document" class="size-[17px]" stroke-width="2" />
                    <span class="sr-only min-[420px]:not-sr-only">CSV</span>
                </button>
            @endif
        @endcan
    </div>

    {{-- Who, and over what period --}}
    <div class="card mt-5 grid gap-3 p-4 sm:grid-cols-3">
        <label class="block">
            <span class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-faint">Customer</span>
            <select wire:model.live="contactId"
                    class="focusable h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink">
                <option value="">Choose a customer…</option>
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}">{{ $customer->displayName() }}</option>
                @endforeach
            </select>
        </label>

        <label class="block">
            <span class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-faint">From</span>
            <input type="date" wire:model.live="from"
                   class="focusable h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink" />
        </label>

        <label class="block">
            <span class="mb-1.5 block text-[12px] font-bold uppercase tracking-wide text-faint">To</span>
            <input type="date" wire:model.live="to"
                   class="focusable h-11 w-full rounded-xl border border-border bg-surface px-3 text-[14.5px] text-ink" />
            @error('to') <span class="mt-1 block text-[12.5px] text-negative">{{ $message }}</span> @enderror
        </label>
    </div>

    @if (! $report)
        <div class="card mt-4 px-5 py-12 text-center">
            <p class="text-[14.5px] text-muted">Pick a customer to see their statement of account.</p>
        </div>
    @else
        @php $contact = $report['contact']; @endphp

        <div class="card mt-4 p-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-[18px] font-bold text-ink">{{ $contact->displayName() }}</h2>
                    <p class="mt-0.5 text-[13.5px] text-muted">
                        {{ $report['from']->toFormattedDateString() }} — {{ $report['to']->toFormattedDateString() }}
                    </p>
                </div>
                <div class="text-right">
                    <span class="block text-[11.5px] font-bold uppercase tracking-wide text-faint">Closing balance</span>
                    <span class="block text-[24px] font-bold tabular-nums {{ $report['closing_balance'] > 0 ? 'text-ink' : 'text-positive' }}">
                        {{ Money::format($report['closing_balance'], $currency) }}
                    </span>
                </div>
            </div>

            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    'Opening' => $report['opening_balance'],
                    'Charged' => $report['totals']['charged'],
                    'Credited' => $report['totals']['credited'],
                    'Paid' => $report['totals']['paid'],
                ] as $label => $value)
                    <div class="rounded-xl border border-border px-3 py-2.5">
                        <span class="block text-[11.5px] font-bold uppercase tracking-wide text-faint">{{ $label }}</span>
                        <span class="block text-[15px] font-semibold tabular-nums text-ink">{{ Money::format($value, $currency) }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- The movements, in date order, with the balance running down the page. --}}
        <div class="card mt-4 overflow-x-auto">
            <table class="w-full min-w-[680px] text-[13.5px]">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">Date</th>
                        <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">Reference</th>
                        <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">Description</th>
                        <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Debit</th>
                        <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Credit</th>
                        <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-border bg-surface-2">
                        <td class="px-4 py-3 text-muted">{{ $report['from']->toDateString() }}</td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 font-semibold text-ink">Balance brought forward</td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-ink">{{ Money::format($report['opening_balance'], $currency) }}</td>
                    </tr>

                    @forelse ($report['lines'] as $line)
                        <tr class="border-b border-border">
                            <td class="px-4 py-3 whitespace-nowrap text-muted">{{ \Illuminate\Support\Carbon::parse($line['date'])->toDateString() }}</td>
                            <td class="px-4 py-3 font-medium text-ink">{{ $line['reference'] ?? '—' }}</td>
                            <td class="px-4 py-3 text-ink-2">{{ $line['description'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink">{{ $line['debit'] > 0 ? Money::format($line['debit'], $currency) : '' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-positive">{{ $line['credit'] > 0 ? Money::format($line['credit'], $currency) : '' }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums text-ink">{{ Money::format($line['balance'], $currency) }}</td>
                        </tr>
                    @empty
                        <tr class="border-b border-border">
                            <td colspan="6" class="px-4 py-8 text-center text-muted">Nothing moved on this account in this period.</td>
                        </tr>
                    @endforelse

                    <tr>
                        <td class="px-4 py-3 text-muted">{{ $report['to']->toDateString() }}</td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 font-bold text-ink">Closing balance</td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3"></td>
                        <td class="px-4 py-3 text-right font-bold tabular-nums text-ink">{{ Money::format($report['closing_balance'], $currency) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- How old what is still open happens to be. Same buckets as the aging
             report, so the customer and the business argue about the money
             rather than about the arithmetic. --}}
        @if (array_sum($report['aging']) > 0)
            <div class="card mt-4 overflow-x-auto">
                <table class="w-full min-w-[560px] text-[13.5px]">
                    <thead>
                        <tr class="border-b border-border">
                            @foreach (Aging::BUCKETS as $key => $label)
                                <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            @foreach (Aging::BUCKETS as $key => $label)
                                @php $value = $report['aging'][$key]; @endphp
                                <td class="px-4 py-3.5 tabular-nums {{ $key === 'over_90' && $value > 0 ? 'font-bold text-negative' : 'font-semibold text-ink' }}">
                                    {{ $value > 0 ? Money::format($value, $currency) : '—' }}
                                </td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
