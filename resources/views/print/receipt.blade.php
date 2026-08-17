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
        /* The one brand touch a till slip gets: the paid total reads as
           this business's colour, not a generic one. A thermal head prints
           one colour regardless, so this only shows on A4/PDF receipts —
           see the .big rule below, unset under the thermal branch. */
        :root { --brand: {{ $company->brandToken('primary', '#0f172a') }}; }
        body {
            font-family: {{ $thermal ? "'Courier New', monospace" : "'Inter', -apple-system, sans-serif" }};
            font-size: {{ $thermal ? '8.5pt' : '10pt' }};
            color: #000; line-height: 1.4;
        }
        /* On screen the slip yields to narrow viewports (an A4 slip is wider
           than a phone); print restores the exact millimetre width, and the
           bottom padding keeps the fixed print bar off the slip. */
        .slip { width: min({{ $width - ($thermal ? 6 : 24) }}mm, calc(100vw - 24px)); margin: 16px auto 96px; }
        @media print { .slip { width: {{ $width - ($thermal ? 6 : 24) }}mm; margin: 0 auto; } .no-print { display: none !important; } }
        .center { text-align: center; }
        .name { font-weight: 800; font-size: {{ $thermal ? '10pt' : '13pt' }}; }
        .letterhead-logo { max-height: 16mm; max-width: 50mm; object-fit: contain; margin: 0 auto 6px; }
        .muted { color: #444; }
        .rule { border-top: 1px dashed #000; margin: 8px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; }
        /* Labels hold their line; a long customer name or reference wraps
           instead of pushing past the slip edge. */
        .row > span:first-child { flex-shrink: 0; }
        .row > span:last-child { text-align: right; overflow-wrap: anywhere; min-width: 0; }
        .big { font-weight: 800; font-size: {{ $thermal ? '11pt' : '14pt' }}; {{ $thermal ? '' : 'color: var(--brand);' }} }
        .qr { display: flex; justify-content: center; margin-top: 10px; page-break-inside: avoid; }
        .qr svg { max-width: 100%; }
        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; display: flex; justify-content: center; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>
<div class="slip">
    <div class="center">
        {{-- A4 only. A thermal head renders an image as slow, muddy dithering
             on 58mm paper, and every millimetre of roll is a cost the business
             pays per sale — so the till slip stays the name in bold type. --}}
        @if (! $thermal && $company->logoUrl())
            <img class="letterhead-logo" src="{{ $company->logoUrl() }}" alt="{{ $company->name }}">
        @endif
        <div class="name">{{ $company->name }}</div>
        @if ($company->motto)<div class="muted">{{ $company->motto }}</div>@endif
        <div class="muted">
            {{ implode(' · ', array_filter([data_get($company->phones, 0), $company->email])) }}
        </div>
        {{-- A till receipt is a fiscal document too, and the NIU is the mention
             an inspector looks for first. Kept to one compact line: thermal
             paper is 58mm wide and every line costs roll. --}}
        @if ($company->tax_id || $company->registration_number)
            <div class="muted">
                {{ implode(' · ', array_filter([
                    $company->tax_id ? 'NIU '.$company->tax_id : null,
                    $company->registration_number ? 'RCCM '.$company->registration_number : null,
                ])) }}
            </div>
        @endif
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

    @if ($company->vat_registered)
        {{-- The amount paid is TTC, so the TVA inside it is shown rather than
             added: a customer cannot reclaim tax that the receipt never names. --}}
        @php
            $receiptVat = \App\Support\Vat::compute(
                [['quantity' => 1, 'unit_price' => $receipt->total]],
                (float) $company->vat_rate,
                true,
                true,
                $receipt->currency,
            );
        @endphp
        <div class="row"><span>Total HT</span><span>{{ Money::format($receiptVat['subtotal'], $receipt->currency) }}</span></div>
        <div class="row">
            <span>dont TVA {{ rtrim(rtrim(number_format((float) $company->vat_rate, 2, '.', ''), '0'), '.') }}%</span>
            <span>{{ Money::format($receiptVat['tax_total'], $receipt->currency) }}</span>
        </div>
    @else
        <div class="center muted" style="margin-top:4px">{{ \App\Support\Vat::exemptionNotice($company) }}</div>
    @endif

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
