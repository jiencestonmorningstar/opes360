<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $manifest->reference }} · {{ $company->name }}</title>
    {{-- Self-contained CSS — the same deterministic-print stance as the other
         print views. An internal loading sheet: no tracking links, no money. --}}
    <style>
        @page { size: A4; margin: 18mm 16mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root { --fs: 17px; --fs-small: 14px; }
        body { font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif; font-size: var(--fs); color: #0f172a; line-height: 1.5; }
        .sheet { width: 100%; max-width: 178mm; margin: 0 auto; padding: 24px 8px 96px; overflow-wrap: break-word; position: relative; }

        @media print {
            :root { --fs: 10.5pt; --fs-small: 8.5pt; }
            body { line-height: 1.45; }
            .sheet { padding: 0; }
            .no-print { display: none !important; }
        }

        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        header > div { min-width: 0; }
        .muted { color: #64748b; }
        .small { font-size: var(--fs-small); }
        .doc-type { font-size: calc(var(--fs) * 1.24); font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #2563eb; text-align: right; }
        .doc-number { font-size: calc(var(--fs) * 1.05); font-weight: 700; text-align: right; }
        .label { font-size: calc(var(--fs-small) * 0.88); font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; margin-bottom: 4px; }

        .trip { display: flex; gap: 32px; margin-top: 28px; flex-wrap: wrap; }

        table { width: 100%; border-collapse: collapse; margin-top: 26px; }
        thead th { text-align: left; font-size: calc(var(--fs-small) * 0.94); font-weight: 700; text-transform: uppercase;
                   letter-spacing: 0.06em; color: #64748b; padding: 0 8px 8px; border-bottom: 1.5pt solid #0f172a; }
        tbody td { padding: 9px 8px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; overflow-wrap: anywhere; }
        tbody tr { page-break-inside: avoid; }
        tfoot td { padding: 10px 8px 0; font-weight: 700; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }

        footer { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; margin-top: 48px; page-break-inside: avoid; }
        .sign { text-align: center; }
        .sign .line { width: 52mm; max-width: 100%; border-bottom: 0.75pt solid #0f172a; margin-bottom: 4px; height: 40px; }

        .powered { margin-top: 28px; text-align: center; font-size: calc(var(--fs-small) * 0.88); color: #94a3b8; }
        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; color: #fff; display: flex; justify-content: center; gap: 16px; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }

        /* Watermarked until dispatched — a loading sheet for a van still
           being loaded must say so, or the depot works off yesterday's plan. */
        .status-mark {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            display: flex; align-items: center; justify-content: center;
        }
        .status-mark span {
            font-size: 96pt; font-weight: 800; letter-spacing: 0.1em;
            color: rgba(220, 38, 38, 0.12); transform: rotate(-24deg);
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .sheet > * { position: relative; z-index: 1; }
    </style>
</head>
<body>
<div class="sheet">
    @if ($watermark)
        <div class="status-mark" aria-hidden="true"><span>{{ $watermark }}</span></div>
    @endif

    <header>
        <div>
            @include('print.partials.letterhead', ['company' => $company])
        </div>
        <div>
            <div class="doc-type">Trip Manifest</div>
            <div class="doc-number">{{ $manifest->reference }}</div>
            <div class="muted small" style="text-align:right; margin-top:6px">
                Departs {{ $manifest->departs_on?->format('M j, Y') }}
            </div>
        </div>
    </header>

    <div class="trip">
        <div>
            <div class="label">Vehicle</div>
            <div>{{ $manifest->vehicle?->vehicle?->label() ?? $manifest->vehicle?->name ?? '—' }}</div>
        </div>
        <div>
            <div class="label">Driver</div>
            <div>{{ $manifest->driver?->name ?? 'Unassigned' }}</div>
        </div>
        <div>
            <div class="label">Stops</div>
            <div>{{ $stops->implode(' · ') ?: '—' }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Ref</th>
                <th style="width:34%">Cargo</th>
                <th>Receiver</th>
                <th>Destination</th>
                <th class="num">Weight (kg)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($shipments as $shipment)
                <tr>
                    <td>{{ $shipment->reference }}</td>
                    <td>{{ $shipment->cargo_description }}</td>
                    <td>{{ $shipment->receiver?->name }}</td>
                    <td>{{ $shipment->to_location }}</td>
                    <td class="num">{{ $shipment->weight_kg !== null ? rtrim(rtrim($shipment->weight_kg, '0'), '.') : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4">{{ $shipments->count() }} shipment(s) aboard</td>
                <td class="num">{{ $totalWeight > 0 ? number_format($totalWeight, 1) : '—' }}</td>
            </tr>
        </tfoot>
    </table>

    @if ($manifest->notes)
        <div style="margin-top:22px">
            <div class="label">Notes</div>
            <div class="small" style="white-space:pre-line">{{ $manifest->notes }}</div>
        </div>
    @endif

    <footer>
        <div class="sign">
            <div class="line"></div>
            <div class="muted small">Loaded and checked — depot</div>
        </div>
        <div class="sign">
            <div class="line"></div>
            <div class="muted small">Cargo received aboard — driver</div>
        </div>
    </footer>

    <div class="powered">Generated with OPES360 · Business made simple · opesware.com</div>
</div>

<div class="print-bar no-print">
    <button onclick="window.print()">Print or Save as PDF</button>
</div>

@if ($autoprint)
    <script @cspNonce>window.addEventListener('load', function () { window.print(); });</script>
@endif
</body>
</html>
