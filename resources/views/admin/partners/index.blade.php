@php
    use App\Support\Money;

    $money = fn ($amount) => Money::format((int) $amount, 'XAF', false);
@endphp

<x-layouts.admin title="Partners" active="partners">
    <div class="px-5 py-8 lg:px-8">
        <h1 class="text-[24px] font-bold tracking-[-0.02em] text-ink">Partners</h1>
        <p class="mt-1 text-[14px] text-muted">
            Secretariats and print shops, what they have earned, and what is waiting to be paid.
        </p>

        @if (session('status'))
            <div class="mt-5 rounded-xl bg-tint-green px-4 py-3 text-[13.5px] font-medium text-positive">{{ session('status') }}</div>
        @endif

        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['Partners', number_format($partners->count()), 'printer', 'blue'],
                ['Owed to partners', $money($owed), 'banknotes', 'green'],
                ['Open requests', number_format($openRequests), 'clock', 'orange'],
            ] as [$label, $value, $icon, $accent])
                <div class="card p-5">
                    <span class="flex size-10 items-center justify-center rounded-xl bg-tint-{{ $accent }}">
                        <x-icon :name="$icon" class="size-[18px] text-accent-{{ $accent }}" stroke-width="1.9" />
                    </span>
                    <p class="tnum mt-3.5 text-[24px] font-bold leading-none tracking-[-0.02em] text-ink">{{ $value }}</p>
                    <p class="mt-1.5 text-[13px] text-muted">{{ $label }}</p>
                </div>
            @endforeach
        </div>

        {{-- Payout queue first: it is the only thing on this page that needs
             somebody to do something. --}}
        <section class="card mt-6 p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                <h2 class="text-[17px] font-bold tracking-[-0.01em] text-ink">Payout requests</h2>
                <form method="GET" class="flex items-center gap-2">
                    <label for="status" class="sr-only">Filter by status</label>
                    <select id="status" name="status" class="h-10 rounded-xl border border-border bg-surface px-3 text-[13.5px] text-ink" onchange="this.form.submit()">
                        @foreach (['' => 'All', 'requested' => 'Waiting', 'paid' => 'Paid', 'rejected' => 'Rejected'] as $value => $label)
                            <option value="{{ $value }}" @selected($selectedStatus === ($value ?: null))>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </div>

            <div class="divide-y divide-border">
                @forelse ($payouts as $payout)
                    @php $partner = $companies[$payout->company_id] ?? null; @endphp
                    <div class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-[15px] font-semibold text-ink">{{ $partner?->name ?? 'Deleted partner' }}</p>
                                <p class="mt-0.5 text-[13px] text-muted">
                                    {{ $payout->created_at?->format('j M Y, H:i') }}
                                    · {{ strtoupper($payout->method ?? '—') }}
                                    · <span class="select-all">{{ $payout->destination ?: 'no destination given' }}</span>
                                </p>
                                @if ($payout->note)
                                    <p class="mt-1 text-[12.5px] text-faint">{{ $payout->note }}</p>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                <span class="tnum text-[16px] font-bold text-ink">{{ $money($payout->amount) }}</span>
                                <span class="rounded-full px-2.5 py-1 text-[11.5px] font-semibold
                                    {{ match ($payout->status) {
                                        'paid' => 'bg-tint-green text-positive',
                                        'rejected' => 'bg-tint-red text-negative',
                                        default => 'bg-tint-orange text-warning',
                                    } }}">
                                    {{ ucfirst($payout->status) }}
                                </span>
                            </div>
                        </div>

                        @if ($payout->isOpen() && auth('admin')->user()?->isAdmin())
                            {{-- Two forms rather than one with a hidden field, so
                                 neither decision can be reached by editing the other. --}}
                            <div class="mt-3.5 flex flex-wrap gap-2.5">
                                @foreach ([['paid', 'Mark as sent', 'bg-fill-positive text-white'], ['rejected', 'Reject', 'border border-border bg-surface text-ink-2']] as [$decision, $label, $classes])
                                    <form method="POST" action="{{ route('admin.partners.payouts.settle', $payout) }}"
                                          class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="decision" value="{{ $decision }}">
                                        @if ($decision === 'rejected')
                                            <label class="sr-only" for="note-{{ $payout->id }}">Reason</label>
                                            <input id="note-{{ $payout->id }}" type="text" name="note" maxlength="500"
                                                   placeholder="Reason (optional)"
                                                   class="h-10 w-[220px] rounded-xl border border-border bg-surface px-3 text-[13.5px] text-ink placeholder:text-faint">
                                        @endif
                                        <button type="submit"
                                                class="tap focusable flex h-10 items-center rounded-xl px-4 text-[13.5px] font-semibold {{ $classes }}">
                                            {{ $label }}
                                        </button>
                                    </form>
                                @endforeach
                            </div>
                        @elseif ($payout->isOpen())
                            <p class="mt-2.5 text-[12.5px] text-faint">Settling a payout needs the Admin role.</p>
                        @else
                            <p class="mt-2.5 text-[12.5px] text-faint">Settled {{ $payout->settled_at?->format('j M Y, H:i') }}</p>
                        @endif
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-[14px] text-muted">No payout requests.</p>
                @endforelse
            </div>
        </section>

        @if ($payouts->hasPages())
            <div class="mt-5">{{ $payouts->links() }}</div>
        @endif

        <section class="card mt-6 p-0">
            <h2 class="border-b border-border px-5 py-4 text-[17px] font-bold tracking-[-0.01em] text-ink">Every partner</h2>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[720px] text-left text-[14px]">
                    <thead>
                        <tr class="border-b border-border text-[12.5px] uppercase tracking-wide text-faint">
                            <th class="px-5 py-3 font-semibold">Partner</th>
                            <th class="px-3 py-3 text-right font-semibold">Clients</th>
                            <th class="px-3 py-3 text-right font-semibold">Signed up</th>
                            <th class="px-3 py-3 text-right font-semibold">Cards</th>
                            <th class="px-3 py-3 text-right font-semibold">Commission</th>
                            <th class="px-3 py-3 text-right font-semibold">Fees</th>
                            <th class="px-5 py-3 text-right font-semibold">Balance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($partners as $row)
                            @php $summary = $row['summary']; @endphp
                            <tr>
                                <td class="px-5 py-3">
                                    <a href="{{ route('admin.companies.show', $row['company']) }}"
                                       class="focusable font-semibold text-brand hover:underline">{{ $row['company']->name }}</a>
                                    <p class="tnum text-[12px] text-faint">{{ $row['company']->partner_code ?? 'no code yet' }}</p>
                                </td>
                                <td class="tnum px-3 py-3 text-right text-ink-2">{{ number_format($summary['clients']) }}</td>
                                <td class="tnum px-3 py-3 text-right text-ink-2">{{ number_format($summary['converted']) }}</td>
                                <td class="tnum px-3 py-3 text-right text-ink-2">{{ number_format($summary['cards']) }}</td>
                                <td class="tnum px-3 py-3 text-right text-positive">{{ $money($summary['earned']) }}</td>
                                <td class="tnum px-3 py-3 text-right text-ink-2">{{ $money($summary['fees']) }}</td>
                                <td class="tnum px-5 py-3 text-right font-bold {{ $summary['balance'] >= 0 ? 'text-ink' : 'text-negative' }}">
                                    {{ $money($summary['balance']) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-5 py-10 text-center text-[14px] text-muted">
                                    No secretariat accounts yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-layouts.admin>
