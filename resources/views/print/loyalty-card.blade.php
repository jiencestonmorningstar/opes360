@php
    $brand = $company->brandToken('primary', '#2563eb');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Loyalty Card · {{ $contact->displayName() }}</title>
    <style>
        @page { size: 91mm 61mm; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; background: #eef2f7; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { box-shadow: none !important; margin: 0 !important; } }

        .sheet { width: 91mm; padding: 3mm; background: #fff; margin: 24px auto; box-shadow: 0 4px 24px rgba(15,23,42,.12); }
        @media screen and (max-width: 380px) { .sheet { zoom: 0.85; } }
        @media screen and (max-width: 320px) { .sheet { zoom: 0.7; } }

        .card { width: 85mm; height: 55mm; padding: 5mm 6mm; border-radius: 3mm; background: {{ $brand }}; color: #fff;
                display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; }
        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 4mm; }
        .brand-name { font-size: 12pt; font-weight: 800; letter-spacing: -0.02em; }
        .card-label { font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.1em; color: rgba(255,255,255,.7); margin-top: 0.5mm; }
        .qr-chip { background: #fff; padding: 1.2mm; flex-shrink: 0; border-radius: 1mm; }
        .qr-chip svg { display: block; width: 15mm; height: 15mm; }

        .holder { font-size: 11pt; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bottom { display: flex; justify-content: space-between; align-items: flex-end; gap: 4mm; }
        .number { font-variant-numeric: tabular-nums; font-size: 8pt; letter-spacing: 0.04em; color: rgba(255,255,255,.85); }
        .since { font-size: 6.5pt; color: rgba(255,255,255,.6); margin-top: 0.5mm; }

        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; display: flex; justify-content: center; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

<div class="sheet">
    <div class="card">
        <div class="top">
            <div>
                <div class="brand-name">{{ $company->name }}</div>
                <div class="card-label">Loyalty Card</div>
            </div>
            <div class="qr-chip">{!! $qrSvg !!}</div>
        </div>
        <div>
            <div class="holder">{{ $contact->displayName() }}</div>
            <div class="bottom">
                <div>
                    <div class="number">{{ $contact->loyalty_card_number }}</div>
                    <div class="since">Member since {{ $contact->loyalty_card_issued_at?->format('M Y') }}</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="print-bar no-print">
    <button type="button" onclick="window.print()">Print / Save as PDF</button>
</div>

</body>
</html>
