@php
    use App\Support\Money;
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $document->number ?? 'Document' }} · {{ $company->name }}</title>
    {{--
        Self-contained CSS, deliberately not the app bundle: print output must be
        deterministic — no dark-mode variables, no viewport breakpoints, and the
        same rendering whether printed from the browser or by headless Chromium.
    --}}
    <style>
        @page { size: A4; margin: 18mm 16mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif;
            font-size: 10.5pt; color: #0f172a; line-height: 1.45;
        }
        .sheet { max-width: 178mm; margin: 0 auto; padding: 24px 8px; }
        @media print { .sheet { padding: 0; } .no-print { display: none !important; } }

        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .brand-name { font-size: 17pt; font-weight: 800; letter-spacing: -0.02em; }
        .muted { color: #64748b; }
        .small { font-size: 8.5pt; }
        .doc-type { font-size: 13pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #2563eb; text-align: right; }
        .doc-number { font-size: 11pt; font-weight: 700; text-align: right; }

        .parties { display: flex; justify-content: space-between; gap: 24px; margin-top: 28px; }
        .label { font-size: 7.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; margin-bottom: 4px; }
        .party-name { font-weight: 700; font-size: 11pt; }

        table { width: 100%; border-collapse: collapse; margin-top: 26px; }
        thead th {
            text-align: left; font-size: 8pt; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #64748b; padding: 0 8px 8px;
            border-bottom: 1.5pt solid #0f172a;
        }
        tbody td { padding: 9px 8px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }

        .totals { margin-top: 14px; margin-left: auto; width: 62mm; }
        .totals .row { display: flex; justify-content: space-between; padding: 4px 8px; }
        .totals .grand { border-top: 1.5pt solid #0f172a; margin-top: 4px; padding-top: 8px; font-weight: 800; font-size: 12pt; }

        footer { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; margin-top: 40px; }
        .verify { display: flex; gap: 12px; align-items: center; }
        .verify svg { display: block; }
        .sign { text-align: center; }
        .sign .line { width: 52mm; border-bottom: 0.75pt solid #0f172a; margin-bottom: 4px; height: 40px; }

        .powered { margin-top: 28px; text-align: center; font-size: 7.5pt; color: #94a3b8; }
        .print-bar {
            position: fixed; inset: auto 0 0 0; background: #0f172a; color: #fff;
            display: flex; justify-content: center; gap: 16px; padding: 12px;
        }
        .print-bar button {
            background: #2563eb; color: #fff; border: 0; border-radius: 8px;
            padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer;
        }
    </style>
</head>
<body>
<div class="sheet">
    <header>
        <div>
            <div class="brand-name">{{ $company->name }}</div>
            @if ($company->motto)<div class="muted small">{{ $company->motto }}</div>@endif
            <div class="muted small" style="margin-top:6px">
                {{ implode(' · ', array_filter([
                    $company->address_line1,
                    trim(($company->city ? $company->city.', ' : '').($company->country ?? ''), ', ') ?: null,
                ])) }}<br>
                {{ implode(' · ', array_filter([data_get($company->phones, 0), $company->email, $company->website])) }}
            </div>
            @if ($company->registration_number)
                <div class="muted small">Reg. {{ $company->registration_number }}</div>
            @endif
        </div>
        <div>
            <div class="doc-type">{{ $document->type->label() }}</div>
            <div class="doc-number">{{ $document->number ?? 'DRAFT' }}</div>
            <div class="muted small" style="text-align:right; margin-top:6px">
                Issued {{ $document->issue_date?->format('M j, Y') }}<br>
                @if ($document->due_date) Due {{ $document->due_date->format('M j, Y') }} @endif
            </div>
        </div>
    </header>

    <div class="parties">
        <div>
            <div class="label">Billed To</div>
            <div class="party-name">{{ $document->contact?->displayName() ?? 'Walk-in customer' }}</div>
            <div class="muted small">
                {{ implode(' · ', array_filter([$document->contact?->email, data_get($document->contact?->phones, 0)])) }}
            </div>
        </div>
        <div style="text-align:right">
            <div class="label">Status</div>
            <div class="party-name">{{ $document->paymentState()['label'] }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:52%">Description</th>
                <th class="num">Qty</th>
                <th class="num">Unit Price</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($document->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num">{{ rtrim(rtrim($line->quantity, '0'), '.') }}</td>
                    <td class="num">{{ Money::format($line->unit_price, $document->currency) }}</td>
                    <td class="num">{{ Money::format($line->line_total, $document->currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="row"><span class="muted">Subtotal</span><span class="num">{{ Money::format($document->subtotal, $document->currency) }}</span></div>
        @if ((float) $document->discount_total > 0)
            <div class="row"><span class="muted">Discount</span><span class="num">−{{ Money::format($document->discount_total, $document->currency) }}</span></div>
        @endif
        @if ((float) $document->tax_total > 0)
            <div class="row"><span class="muted">Tax</span><span class="num">{{ Money::format($document->tax_total, $document->currency) }}</span></div>
        @endif
        <div class="row grand"><span>Total</span><span class="num">{{ Money::format($document->total, $document->currency) }}</span></div>
        @if ((float) $document->amount_paid > 0)
            <div class="row"><span class="muted">Paid</span><span class="num">−{{ Money::format($document->amount_paid, $document->currency) }}</span></div>
            <div class="row" style="font-weight:700"><span>Balance due</span><span class="num">{{ Money::format($document->balance, $document->currency) }}</span></div>
        @endif
    </div>

    @if ($document->notes)
        <div style="margin-top:22px">
            <div class="label">Notes</div>
            <div class="small">{{ $document->notes }}</div>
        </div>
    @endif

    <footer>
        @if ($qrSvg)
            <div class="verify">
                {!! $qrSvg !!}
                <div class="muted small" style="max-width:46mm">
                    Scan to verify this {{ strtolower($document->type->label()) }} is authentic.<br>
                    {{ $document->verificationToken->publicUrl() }}
                </div>
            </div>
        @else
            <div></div>
        @endif

        <div class="sign">
            <div class="line"></div>
            <div class="muted small">Authorised signature</div>
        </div>
    </footer>

    <div class="powered">Generated with OPES360 · Business made simple · opesware.com</div>
</div>

<div class="print-bar no-print">
    <button onclick="window.print()">Print or Save as PDF</button>
</div>

@if ($autoprint)
    <script>window.addEventListener('load', function () { window.print(); });</script>
@endif
</body>
</html>
