<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $shipment->reference }} · {{ $company->name }}</title>
    {{-- Self-contained CSS, deliberately not the app bundle — the same
         deterministic-print stance as print/delivery-note.blade.php. --}}
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
        header > div, .parties > div, footer > div { min-width: 0; }
        .muted { color: #64748b; }
        .small { font-size: var(--fs-small); }
        .doc-type { font-size: calc(var(--fs) * 1.24); font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #2563eb; text-align: right; }
        .doc-number { font-size: calc(var(--fs) * 1.05); font-weight: 700; text-align: right; }

        .parties { display: flex; justify-content: space-between; gap: 24px; margin-top: 28px; }
        .label { font-size: calc(var(--fs-small) * 0.88); font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; margin-bottom: 4px; }
        .party-name { font-weight: 700; font-size: calc(var(--fs) * 1.05); }

        table { width: 100%; border-collapse: collapse; margin-top: 26px; }
        thead th { text-align: left; font-size: calc(var(--fs-small) * 0.94); font-weight: 700; text-transform: uppercase;
                   letter-spacing: 0.06em; color: #64748b; padding: 0 8px 8px; border-bottom: 1.5pt solid #0f172a; }
        tbody td { padding: 9px 8px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; overflow-wrap: anywhere; }
        tbody tr { page-break-inside: avoid; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }

        footer { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; margin-top: 48px; page-break-inside: avoid; }
        .verify { display: flex; gap: 12px; align-items: center; min-width: 0; }
        .verify svg { display: block; flex-shrink: 0; }
        .verify .small { overflow-wrap: anywhere; }
        .sign { text-align: center; }
        .sign .line { width: 52mm; max-width: 100%; border-bottom: 0.75pt solid #0f172a; margin-bottom: 4px; height: 40px; }

        .powered { margin-top: 28px; text-align: center; font-size: calc(var(--fs-small) * 0.88); color: #94a3b8; }
        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; color: #fff; display: flex; justify-content: center; gap: 16px; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }

        /*
         * The status watermark — the Watermarks doctrine: a cancelled
         * shipment's waybill that prints clean is live cargo paperwork again.
         */
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

        @media screen and (max-width: 640px) {
            :root { --fs: 17px; --fs-small: 14px; }
            header, .parties, footer { flex-direction: column; gap: 16px; }
            .doc-type, .doc-number { text-align: left; }
            .num { text-align: left; }
            .sign .line { max-width: none; }
        }
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
            <div class="doc-type">Waybill</div>
            <div class="doc-number">{{ $shipment->reference }}</div>
            <div class="muted small" style="text-align:right; margin-top:6px">
                Booked {{ $shipment->created_at?->format('M j, Y') }}<br>
                {{ $shipment->from_location }} → {{ $shipment->to_location }}
            </div>
        </div>
    </header>

    <div class="parties">
        <div>
            <div class="label">Sender</div>
            <div class="party-name">{{ $shipment->sender?->name ?? '—' }}</div>
            <div class="muted small">{{ $shipment->from_location }}</div>
        </div>
        <div style="text-align:right">
            <div class="label">Receiver</div>
            <div class="party-name">{{ $shipment->receiver?->name ?? '—' }}</div>
            <div class="muted small">{{ $shipment->to_location }}</div>
        </div>
    </div>

    {{-- Deliberately no freight amount: the waybill travels with the goods
         through third hands. The money lives on the invoice. --}}
    <table>
        <thead>
            <tr>
                <th style="width:60%">Cargo</th>
                <th class="num">Weight (kg)</th>
                <th class="num">Declared value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $shipment->cargo_description }}</td>
                <td class="num">{{ $shipment->weight_kg !== null ? rtrim(rtrim($shipment->weight_kg, '0'), '.') : '—' }}</td>
                <td class="num">{{ $shipment->declared_value !== null ? number_format((float) $shipment->declared_value) : '—' }}</td>
            </tr>
        </tbody>
    </table>

    <footer>
        <div class="verify">
            {!! $qrSvg !!}
            <div class="muted small" style="max-width:46mm">
                Scan to track this shipment.<br>
                {{ $shipment->trackingUrl() }}
            </div>
        </div>

        <div class="sign">
            <div class="line"></div>
            <div class="muted small">Received in good order — name, date, signature</div>
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
