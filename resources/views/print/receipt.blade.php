@php
    use App\Support\Money;

    $width = $receipt->pageWidthMm();
    $thermal = in_array($receipt->format, ['thermal58', 'thermal80'], true);
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $receipt->number }} · {{ $company->name }}</title>
    <style>
        @page { size: {{ $width }}mm auto; margin: {{ $thermal ? '3mm' : '12mm' }}; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: {{ $thermal ? "'Courier New', monospace" : "'Inter', -apple-system, sans-serif" }};
            font-size: {{ $thermal ? '8.5pt' : '10pt' }};
            color: #000; line-height: 1.4;
        }
        .slip { width: {{ $width - ($thermal ? 6 : 24) }}mm; margin: 16px auto; }
        @media print { .slip { margin: 0 auto; } .no-print { display: none !important; } }
        .center { text-align: center; }
        .name { font-weight: 800; font-size: {{ $thermal ? '10pt' : '13pt' }}; }
        .muted { color: #444; }
        .rule { border-top: 1px dashed #000; margin: 8px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        .big { font-weight: 800; font-size: {{ $thermal ? '11pt' : '14pt' }}; }
        .qr { display: flex; justify-content: center; margin-top: 10px; }
        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; display: flex; justify-content: center; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>
<div class="slip">
    <div class="center">
        <div class="name">{{ $company->name }}</div>
        @if ($company->motto)<div class="muted">{{ $company->motto }}</div>@endif
        <div class="muted">
            {{ implode(' · ', array_filter([data_get($company->phones, 0), $company->email])) }}
        </div>
    </div>

    <div class="rule"></div>

    <div class="row"><span>Receipt</span><span>{{ $receipt->number }}</span></div>
    <div class="row"><span>Date</span><span>{{ $receipt->issued_at->format('M j, Y g:ia') }}</span></div>
    @if ($receipt->contact)
        <div class="row"><span>Customer</span><span>{{ $receipt->contact->displayName() }}</span></div>
    @endif
    @if ($receipt->payment)
        <div class="row"><span>Method</span><span>{{ $receipt->payment->method->label() }}</span></div>
        @if ($receipt->payment->reference)
            <div class="row"><span>Ref</span><span>{{ $receipt->payment->reference }}</span></div>
        @endif
    @endif
    @if ($receipt->cashier)
        <div class="row"><span>Cashier</span><span>{{ $receipt->cashier->firstName() }}</span></div>
    @endif

    <div class="rule"></div>

    <div class="row big"><span>PAID</span><span>{{ Money::format($receipt->total, $receipt->currency) }}</span></div>

    <div class="rule"></div>

    @if ($qrSvg)
        <div class="qr">{!! $qrSvg !!}</div>
        <div class="center muted" style="margin-top:6px">Scan to verify this receipt</div>
    @endif

    <div class="center muted" style="margin-top:10px">
        Thank you for your business!<br>
        Powered by OPES360 · opesware.com
    </div>
</div>

<div class="print-bar no-print">
    <button onclick="window.print()">Print or Save as PDF</button>
</div>

@if ($autoprint)
    <script @cspNonce>window.addEventListener('load', function () { window.print(); });</script>
@endif
</body>
</html>
