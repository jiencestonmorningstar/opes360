@php
    $phone = data_get($company->phones, 0);
    $address = collect([
        $company->address_line1,
        $company->city,
        $company->country,
    ])->filter()->implode(' · ');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $paper->title }} · {{ $company->name }}</title>
    <style>
        @page { size: A4; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; background: #eef2f7; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { box-shadow: none !important; margin: 0 !important; } }

        .sheet { width: 210mm; min-height: 297mm; background: #fff; margin: 24px auto; padding: 18mm 16mm 14mm; display: flex; flex-direction: column; box-shadow: 0 4px 24px rgba(15,23,42,.12); }

        /* Same letterhead as the stationery, so a contract and an invoice
           visibly come from the same business. */
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16mm; border-bottom: 1.5pt solid #0f172a; padding-bottom: 6mm; }
        .name { font-size: 20pt; font-weight: 800; letter-spacing: -0.02em; }
        .motto { font-size: 9.5pt; color: #64748b; margin-top: 1mm; }
        .head-meta { text-align: right; font-size: 8pt; color: #64748b; line-height: 1.7; }

        .doc-meta { display: flex; justify-content: space-between; align-items: baseline; gap: 10mm; padding: 5mm 0 0; font-size: 8.5pt; color: #64748b; }
        .doc-title { font-size: 13pt; font-weight: 800; color: #0f172a; letter-spacing: -0.01em; }
        .doc-ref { font-variant-numeric: tabular-nums; font-weight: 700; color: #0f172a; }

        .body { flex: 1; padding-top: 7mm; font-size: 10pt; line-height: 1.65; }
        .body h1 { font-size: 17pt; font-weight: 800; letter-spacing: -0.02em; text-align: center; margin: 6mm 0 4mm; }
        .body h2 { font-size: 10.5pt; font-weight: 800; margin: 6mm 0 2mm; letter-spacing: -0.01em; }
        .body h2:first-child { margin-top: 0; }
        .body p { margin-bottom: 3.5mm; }
        .body ul { margin: 0 0 3.5mm 5mm; }
        .body li { margin-bottom: 1.5mm; }
        .body strong { font-weight: 700; }

        /* Signature blocks. A line to sign on is the difference between a
           document someone executes and one they file. */
        .sign { display: flex; gap: 14mm; margin-top: 8mm; page-break-inside: avoid; }
        .sign-block { flex: 1; }
        .sign-rule { border-bottom: 0.75pt solid #94a3b8; height: 14mm; }
        .sign-label { font-size: 7.5pt; color: #64748b; padding-top: 1.5mm; }

        .notice { margin-top: 8mm; padding: 3.5mm 4mm; border-left: 2pt solid #f59e0b; background: #fffbeb; font-size: 7.5pt; color: #92400e; line-height: 1.55; page-break-inside: avoid; }

        .foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 10mm; border-top: 0.5pt solid #cbd5e1; margin-top: 8mm; padding-top: 4mm; font-size: 7.5pt; color: #94a3b8; }
        .qr { text-align: center; }
        .qr svg { display: block; }
        .qr-label { font-size: 6.5pt; color: #94a3b8; padding-top: 1mm; }
        .draft { color: #b45309; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; }

        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; display: flex; justify-content: center; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

<div class="sheet">
    <div class="head">
        <div>
            <div class="name">{{ $company->name }}</div>
            @if ($company->motto)
                <div class="motto">{{ $company->motto }}</div>
            @endif
        </div>
        <div class="head-meta">
            @if ($address) <div>{{ $address }}</div> @endif
            @if ($phone) <div>{{ $phone }}</div> @endif
            @if ($company->email) <div>{{ $company->email }}</div> @endif
        </div>
    </div>

    <div class="doc-meta">
        <span class="doc-title">{{ $paper->title }}</span>
        <span>
            @if ($paper->reference)
                <span class="doc-ref">{{ $paper->reference }}</span>
            @else
                <span class="draft">Draft — not issued</span>
            @endif
        </span>
    </div>

    <div class="body">
        {!! $bodyHtml !!}

        @if (($paper->template()['binding'] ?? false))
            <div class="sign">
                <div class="sign-block">
                    <div class="sign-rule"></div>
                    <div class="sign-label">For and on behalf of {{ $company->name }} · Date</div>
                </div>
                <div class="sign-block">
                    <div class="sign-rule"></div>
                    <div class="sign-label">{{ $paper->recipient ?: 'Counterparty' }} · Date</div>
                </div>
            </div>
        @endif
    </div>

    @if ($notice)
        <div class="notice">{{ $notice }}</div>
    @endif

    <div class="foot">
        <div>
            <div>{{ $company->name }}</div>
            @if ($paper->issued_at)
                <div>Issued {{ $paper->issued_at->format('j M Y') }}</div>
            @endif
        </div>

        @if ($qrSvg)
            <div class="qr">
                {!! $qrSvg !!}
                <div class="qr-label">Scan to verify</div>
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
