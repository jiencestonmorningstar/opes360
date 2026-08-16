<div class="px-5 pb-8 lg:px-6 lg:pt-6">

    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-[25px] font-bold leading-tight tracking-[-0.03em] text-ink lg:text-[28px]">Document analytics</h1>
            <p class="mt-1 text-[14.5px] text-muted">
                {{ $from->format('j M') }} – {{ $to->format('j M Y') }}. Restricted documents count here, but never by name.
            </p>
        </div>
    </div>

    {{-- Range switch --}}
    <div class="no-scrollbar -mx-5 mt-5 flex gap-2 overflow-x-auto px-5 lg:mx-0 lg:px-0" role="group" aria-label="Period">
        @foreach (['week' => 'This week', 'month' => 'This month', 'quarter' => 'This quarter', 'year' => 'This year'] as $key => $label)
            <button type="button" wire:click="setRange('{{ $key }}')"
                    aria-pressed="{{ $range === $key ? 'true' : 'false' }}"
                    class="focusable flex h-10 shrink-0 items-center rounded-full px-4 text-[13.5px] font-semibold transition-colors
                           {{ $range === $key ? 'bg-fill-brand text-white' : 'border border-border bg-surface text-ink-2 hover:bg-surface-2' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- Headline figures --}}
    @php
        $cards = [
            ['label' => 'Created', 'value' => $summary['created']],
            ['label' => 'Issued', 'value' => $summary['issued']],
            ['label' => 'Days draft → issued', 'value' => $summary['draft_to_issue_days']],
            ['label' => 'Signature completion', 'value' => $summary['signature_rate'] !== null ? $summary['signature_rate'].'%' : null],
            ['label' => 'Hours to sign', 'value' => $summary['signature_hours']],
            ['label' => 'Share link opens', 'value' => $summary['share_opens']],
        ];
    @endphp
    <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-3">
        @foreach ($cards as $card)
            <div class="card p-4">
                <p class="text-[11.5px] font-bold uppercase tracking-wide text-faint">{{ $card['label'] }}</p>
                <p class="mt-1.5 text-[20px] font-bold tabular-nums text-ink">{{ $card['value'] ?? '—' }}</p>
            </div>
        @endforeach
    </div>

    {{-- Expiring soon — a fact of right now, not of the period --}}
    <div class="mt-3 grid grid-cols-1">
        <div class="card p-4">
            <p class="text-[11.5px] font-bold uppercase tracking-wide text-faint">Expiring within {{ \App\Support\DocumentAnalytics::EXPIRY_HORIZON_DAYS }} days</p>
            <p class="mt-1.5 text-[18px] font-bold tabular-nums {{ $summary['expiring_soon'] > 0 ? 'text-warning' : 'text-ink' }}">
                {{ $summary['expiring_soon'] }}
            </p>
        </div>
    </div>

    {{-- By type --}}
    <h2 class="mt-7 text-[17px] font-bold text-ink">By type</h2>
    <div class="card mt-3 overflow-x-auto">
        <table class="w-full min-w-[420px] text-[13.5px]">
            <thead>
                <tr class="border-b border-border">
                    <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">Type</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Created</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Issued</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($byKind as $i => $row)
                    <tr class="{{ $i > 0 ? 'border-t border-border' : '' }}">
                        <td class="px-4 py-3 font-semibold text-ink">{{ $row['label'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-muted">{{ $row['created'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-muted">{{ $row['issued'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-[13.5px] text-muted">No documents in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Approval bottlenecks --}}
    <h2 class="mt-7 text-[17px] font-bold text-ink">Approval bottlenecks</h2>
    <p class="mt-1 text-[13px] text-muted">Which workflow step holds documents longest, from the engine's own record.</p>
    <div class="card mt-3 overflow-x-auto">
        <table class="w-full min-w-[420px] text-[13.5px]">
            <thead>
                <tr class="border-b border-border">
                    <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">Step</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Avg hours held</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Documents</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($bottlenecks as $i => $row)
                    <tr class="{{ $i > 0 ? 'border-t border-border' : '' }}">
                        <td class="px-4 py-3 font-semibold text-ink">{{ $row['step'] }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $i === 0 && $row['avg_hours'] > 0 ? 'text-warning' : 'text-ink' }}">
                            {{ $row['avg_hours'] }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-muted">{{ $row['count'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-[13.5px] text-muted">No approvals ran in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Most shared --}}
    <h2 class="mt-7 text-[17px] font-bold text-ink">Papers that travel</h2>
    <p class="mt-1 text-[13px] text-muted">External share-link opens in this period.</p>
    <div class="card mt-3 overflow-x-auto">
        <table class="w-full min-w-[420px] text-[13.5px]">
            <thead>
                <tr class="border-b border-border">
                    <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">Document</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Opens</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($mostShared as $i => $row)
                    <tr class="{{ $i > 0 ? 'border-t border-border' : '' }}">
                        <td class="px-4 py-3">
                            <span class="font-semibold {{ $row['restricted'] ? 'italic text-muted' : 'text-ink' }}">{{ $row['label'] }}</span>
                            @if ($row['reference'])
                                <span class="ml-2 text-[12px] text-faint">{{ $row['reference'] }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-ink">{{ $row['opens'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-4 py-8 text-center text-[13.5px] text-muted">No share links were opened in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Storage by folder --}}
    <h2 class="mt-7 text-[17px] font-bold text-ink">Storage by folder</h2>
    <p class="mt-1 text-[13px] text-muted">Filed binaries only — composed text lives in the database, not on disk.</p>
    <div class="card mt-3 overflow-x-auto">
        <table class="w-full min-w-[420px] text-[13.5px]">
            <thead>
                <tr class="border-b border-border">
                    <th class="px-4 py-3 text-left text-[11.5px] font-bold uppercase tracking-wide text-faint">Folder</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Files</th>
                    <th class="px-4 py-3 text-right text-[11.5px] font-bold uppercase tracking-wide text-faint">Size</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($storage as $i => $row)
                    <tr class="{{ $i > 0 ? 'border-t border-border' : '' }}">
                        <td class="px-4 py-3 font-semibold text-ink">{{ $row['folder'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-muted">{{ $row['files'] }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums text-ink">
                            @if ($row['bytes'] >= 1048576)
                                {{ number_format($row['bytes'] / 1048576, 1) }} MB
                            @elseif ($row['bytes'] >= 1024)
                                {{ number_format($row['bytes'] / 1024, 1) }} KB
                            @else
                                {{ $row['bytes'] }} B
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="px-4 py-8 text-center text-[13.5px] text-muted">No files have been uploaded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>
