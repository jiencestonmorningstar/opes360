@php
    use App\Support\Money;

    $currency = $company?->currency ?? 'USD';
    $phone = data_get($company?->phones, 0);
    $address = collect([
        $company?->address_line1,
        $company?->city,
        $company?->country,
    ])->filter()->implode(' · ');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of Account · {{ $contact->displayName() }}</title>
    <style>
        @page { size: A4; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; background: #eef2f7; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { width: 210mm; min-height: 297mm; padding: 18mm 16mm 14mm; box-shadow: none !important; margin: 0 !important; } }

        /* Screen previews yield to the viewport; @media print above restores
           the exact A4 geometry. Body padding keeps the print bar clear. */
        @media screen { body { padding-bottom: 84px; } }
        .sheet { width: min(210mm, 100%); min-height: 297mm; background: #fff; margin: 24px auto; padding: 18mm 16mm 14mm; display: flex; flex-direction: column; box-shadow: 0 4px 24px rgba(15,23,42,.12); }
        @media screen and (max-width: 700px) { .sheet { min-height: 0; margin: 12px auto; padding: 8mm 6mm 10mm; } }

        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16mm; border-bottom: 1.5pt solid #0f172a; padding-bottom: 6mm; }
        .name { font-size: 20pt; font-weight: 800; letter-spacing: -0.02em; }
        .motto { font-size: 9.5pt; color: #64748b; margin-top: 1mm; }
        .head-meta { text-align: right; font-size: 8pt; color: #64748b; line-height: 1.7; }

        .doc-meta { display: flex; justify-content: space-between; align-items: baseline; gap: 10mm; padding: 6mm 0 0; }
        .doc-title { font-size: 15pt; font-weight: 800; letter-spacing: -0.01em; }
        .period { font-size: 9pt; color: #64748b; }

        .party { padding-top: 5mm; font-size: 9.5pt; line-height: 1.6; }
        .party-label { font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.06em; color: #94a3b8; }
        .party-name { font-weight: 700; font-size: 11pt; }

        table { width: 100%; border-collapse: collapse; margin-top: 6mm; font-size: 9pt; }
        th { text-align: left; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; border-bottom: 1pt solid #0f172a; padding: 0 2mm 2mm; }
        td { padding: 2.2mm 2mm; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; overflow-wrap: anywhere; }
        /* A ledger row split across a page break reads as two half-entries. */
        tbody tr { page-break-inside: avoid; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .ref { font-variant-numeric: tabular-nums; font-weight: 600; }

        .totals { margin-left: auto; width: 70mm; max-width: 100%; margin-top: 5mm; font-size: 9.5pt; page-break-inside: avoid; }
        .totals .row { display: flex; justify-content: space-between; padding: 1.6mm 2mm; }
        .totals .closing { border-top: 1.5pt solid #0f172a; font-weight: 800; font-size: 11.5pt; padding-top: 2.5mm; }

        .note { margin-top: 6mm; font-size: 8pt; color: #64748b; line-height: 1.6; }
        .empty { padding: 12mm 0; text-align: center; color: #94a3b8; font-size: 9.5pt; }

        .foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 10mm; border-top: 0.5pt solid #cbd5e1; margin-top: auto; padding-top: 4mm; font-size: 7.5pt; color: #94a3b8; page-break-inside: avoid; }
        .qr { text-align: center; }
        .qr svg { display: block; margin: 0 auto; }
        .qr-label { font-size: 6.5pt; color: #94a3b8; padding-top: 1mm; }

        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; display: flex; justify-content: center; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

<div class="sheet">
    <div class="head">
        <div>
            <div class="name">{{ $company?->name }}</div>
            @if ($company?->motto)<div class="motto">{{ $company->motto }}</div>@endif
        </div>
        <div class="head-meta">
            @if ($address){{ $address }}<br>@endif
            @if ($company?->email){{ $company->email }}@endif
            @if ($phone) · {{ $phone }}@endif
        </div>
    </div>

    <div class="doc-meta">
        <div class="doc-title">Statement of Account</div>
        <div class="period">{{ $from->format('j M Y') }} — {{ $to->format('j M Y') }}</div>
    </div>

    <div class="party">
        <div class="party-label">For</div>
        <div class="party-name">{{ $contact->displayName() }}</div>
        @if ($contact->email){{ $contact->email }}@endif
    </div>

    @if ($lines->isEmpty())
        <div class="empty">No invoices or payments in this period.</div>
    @else
        <table>
            <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Description</th>
                <th class="num">Charges</th>
                <th class="num">Payments</th>
                <th class="num">Balance</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ \Illuminate\Support\Carbon::parse($line['date'])->format('j M Y') }}</td>
                    <td class="ref">{{ $line['reference'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $line['debit'] > 0 ? Money::format($line['debit'], $currency) : '—' }}</td>
                    <td class="num">{{ $line['credit'] > 0 ? Money::format($line['credit'], $currency) : '—' }}</td>
                    <td class="num">{{ Money::format($line['balance'], $currency) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="totals">
            <div class="row"><span>Total charges</span><span class="num">{{ Money::format($totalDebits, $currency) }}</span></div>
            <div class="row"><span>Total payments</span><span class="num">{{ Money::format($totalCredits, $currency) }}</span></div>
            <div class="row closing"><span>Balance due</span><span class="num">{{ Money::format($closing, $currency) }}</span></div>
        </div>
    @endif

    <p class="note">
        This statement reflects invoices, credit notes and payments recorded between the dates shown.
        Queries should reach {{ $company?->email ?? 'the business' }} within 14 days of the statement date.
    </p>

    <div class="foot">
        <div>
            <div>Generated {{ now()->format('j M Y · g:ia') }} by {{ $company?->name }}</div>
            <div>Powered by Opes360</div>
        </div>

        @if ($qrSvg)
            <div class="qr">
                {!! $qrSvg !!}
                <div class="qr-label">Scan to verify this business</div>
            </div>
        @endif
    </div>
</div>

<div class="print-bar no-print">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>

@if ($autoprint)
    <script @cspNonce>window.addEventListener('load', function () { window.print(); });</script>
@endif

</body>
</html>
