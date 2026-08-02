@php
    use App\Support\Money;

    $money = fn ($amount) => Money::format((int) $amount, 'XAF', false);
    $percent = rtrim(rtrim(number_format($rate * 100, 1), '0'), '.');

    $inputClass = 'h-12 w-full rounded-xl border border-border bg-surface px-3.5 text-[15px] text-ink placeholder:text-faint focus:border-brand focus:outline-none focus:ring-2 focus:ring-brand/20';
    $labelClass = 'mb-1.5 block text-[13px] font-semibold text-ink-2';
@endphp

<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Earnings</h1>
    <p class="mt-1 text-[14.5px] text-muted">Commission in, card fees out, and what is left.</p>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
    @endif

    {{-- The balance first and largest: it is the only number anyone opens this
         page to see. The three that produce it sit under it, in order. --}}
    <div class="card mt-5 p-6">
        <p class="text-[12px] font-semibold uppercase tracking-wide text-faint">Available to withdraw</p>
        <p class="tnum mt-1.5 text-[34px] font-bold leading-none tracking-[-0.03em] {{ $summary['balance'] >= 0 ? 'text-ink' : 'text-negative' }}">
            {{ $money($summary['balance']) }}
        </p>

        @if ($summary['balance'] < 0)
            <p class="mt-3 text-[13.5px] leading-relaxed text-muted">
                Card fees have run ahead of commission. This is settled against your next
                invoice — nothing is owed today.
            </p>
        @elseif (! $summary['can_request'])
            <p class="mt-3 text-[13.5px] leading-relaxed text-muted">
                Payouts start at {{ $money($summary['minimum']) }}. Below that the transfer fee eats the transfer.
            </p>
        @endif

        <dl class="mt-5 grid grid-cols-3 gap-3 border-t border-border pt-4">
            @foreach ([
                ['Commission', $summary['earned'], 'text-positive'],
                ['Card fees', $summary['fees'], 'text-ink-2'],
                ['Paid out', $summary['withdrawn'], 'text-ink-2'],
            ] as [$label, $value, $tone])
                <div>
                    <dt class="text-[12px] font-medium text-faint">{{ $label }}</dt>
                    <dd class="tnum mt-0.5 text-[15px] font-bold {{ $tone }}">{{ $money($value) }}</dd>
                </div>
            @endforeach
        </dl>

        @can('partners.withdraw')
            @if ($summary['can_request'] && ! $requesting)
                <button type="button" wire:click="startRequest"
                        class="tap focusable mt-5 flex h-12 w-full items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90 sm:w-auto">
                    Request payout
                </button>
            @endif
        @endcan
    </div>

    @if ($requesting)
        <div class="card mt-4 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Request {{ $money($summary['balance']) }}</h2>
            <p class="mt-1 text-[13.5px] leading-relaxed text-muted">
                The whole balance goes at once. The amount is taken from the ledger when you
                submit, so it will match whatever you have earned by then.
            </p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $labelClass }}" for="payout-method">Send it by</label>
                    <select id="payout-method" wire:model="method" class="{{ $inputClass }}">
                        <option value="mtn">MTN Mobile Money</option>
                        <option value="orange">Orange Money</option>
                        <option value="bank">Bank transfer</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}" for="payout-destination">Number or account</label>
                    <input id="payout-destination" type="text" wire:model="destination" class="{{ $inputClass }}" placeholder="+237 6…">
                    @error('destination') <p class="mt-1.5 text-[13px] font-medium text-negative">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                <button type="button" wire:click="requestPayout"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl bg-fill-brand px-6 text-[15px] font-semibold text-white transition-opacity hover:opacity-90">
                    Send request
                </button>
                <button type="button" wire:click="cancel"
                        class="tap focusable flex h-12 items-center justify-center rounded-xl border border-border bg-surface px-6 text-[15px] font-semibold text-ink">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    <div class="mt-4 grid grid-cols-3 gap-3">
        @foreach ([
            ['Clients', $summary['clients'], 'users'],
            ['Signed up', $summary['converted'], 'check-circle'],
            ['Cards issued', $summary['cards'], 'printer'],
        ] as [$label, $value, $icon])
            <div class="card p-4">
                <span class="flex size-9 items-center justify-center rounded-lg bg-tint-blue">
                    <x-icon :name="$icon" class="size-[17px] text-accent-blue" stroke-width="1.9" />
                </span>
                <p class="tnum mt-3 text-[21px] font-bold leading-none tracking-[-0.02em] text-ink">{{ number_format($value) }}</p>
                <p class="mt-1 text-[12.5px] text-muted">{{ $label }}</p>
            </div>
        @endforeach
    </div>

    {{-- Commission --}}
    <div class="card mt-4 p-5">
        <div class="flex items-baseline justify-between gap-3">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Commission</h2>
            <span class="text-[12.5px] text-faint">{{ $percent }}% of each payment</span>
        </div>

        <div class="mt-4 divide-y divide-border">
            @forelse ($commissions as $commission)
                <div class="flex items-center gap-3 py-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-tint-green">
                        <x-icon name="trending-up" class="size-[16px] text-accent-green" stroke-width="2" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[14px] font-semibold text-ink">{{ $commission->sourceCompany?->name ?? 'A referred business' }}</p>
                        <p class="text-[12px] text-faint">
                            {{ $commission->created_at?->format('j M Y') }} · on {{ $money($commission->base_amount) }}
                        </p>
                    </div>
                    <span class="tnum shrink-0 text-[14px] font-bold text-positive">+{{ $money($commission->amount) }}</span>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] leading-relaxed text-muted">
                    Nothing yet. Commission arrives the first time a business you enrolled pays for a plan.
                </p>
            @endforelse
        </div>
    </div>

    {{-- Card fees --}}
    <div class="card mt-4 p-5">
        <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Cards issued</h2>

        <div class="mt-4 divide-y divide-border">
            @forelse ($issuances as $issuance)
                <div class="flex items-center gap-3 py-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-tint-slate">
                        <x-icon name="printer" class="size-[16px] text-accent-slate" stroke-width="2" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-[14px] font-semibold text-ink">{{ $issuance->subject_name }}</p>
                        <p class="truncate text-[12px] text-faint">
                            {{ $issuance->created_at?->format('j M Y') }} · {{ ucfirst($issuance->asset) }} · {{ $issuance->design }}
                        </p>
                    </div>
                    <span class="tnum shrink-0 text-[14px] font-semibold text-ink-2">−{{ $money($issuance->fee) }}</span>
                </div>
            @empty
                <p class="py-6 text-center text-[13.5px] text-muted">No cards issued yet.</p>
            @endforelse
        </div>
    </div>

    @if ($payouts->isNotEmpty())
        <div class="card mt-4 p-5">
            <h2 class="text-[17px] font-bold tracking-[-0.02em] text-ink">Payouts</h2>

            <div class="mt-4 divide-y divide-border">
                @foreach ($payouts as $payout)
                    <div class="flex items-center gap-3 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="tnum text-[14px] font-semibold text-ink">{{ $money($payout->amount) }}</p>
                            <p class="truncate text-[12px] text-faint">
                                {{ $payout->created_at?->format('j M Y') }} · {{ strtoupper($payout->method ?? '—') }}
                            </p>
                        </div>
                        <span class="shrink-0 rounded-full px-2.5 py-1 text-[11.5px] font-semibold
                            {{ match ($payout->status) {
                                'paid' => 'bg-tint-green text-positive',
                                'rejected' => 'bg-tint-red text-negative',
                                default => 'bg-tint-orange text-warning',
                            } }}">
                            {{ ucfirst($payout->status) }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
