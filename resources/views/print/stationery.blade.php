@php
    use App\Support\Money;

    $phone = data_get($company->phones, 0);
    $isA3 = $size === 'a3';
    // Null means the original look, so existing letterheads print unchanged.
    $design = in_array($company->letterhead_design, ['rule', 'banner', 'sidebar', 'crest'], true)
        ? $company->letterhead_design
        : 'rule';
    $brand = $company->brandToken('primary', '#2563eb');
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ ucfirst($asset) }} · {{ $company->name }}</title>
    <style>
        /* Page size follows the asset: stationery is cut to its own trim size. */
        @page {
            size: {{ $asset === 'letterhead' ? ($isA3 ? 'A3' : 'A4') : ($asset === 'card' ? '91mm 61mm' : 'A4') }};
            margin: {{ $asset === 'card' ? '0' : '0' }};
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; background: #eef2f7; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { box-shadow: none !important; margin: 0 !important; } }

        .sheet { background: #fff; margin: 24px auto; box-shadow: 0 4px 24px rgba(15,23,42,.12); }

        /* Letterhead — the four designs share the sheet; .design-* restyles the
           head and foot without ever intruding on the writable body. */
        .letterhead { width: {{ $isA3 ? '297mm' : '210mm' }}; min-height: {{ $isA3 ? '420mm' : '297mm' }}; padding: 18mm 16mm; display: flex; flex-direction: column; }
        .lh-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16mm; border-bottom: 1.5pt solid #0f172a; padding-bottom: 6mm; }
        .lh-name { font-size: {{ $isA3 ? '26pt' : '20pt' }}; font-weight: 800; letter-spacing: -0.02em; }
        .lh-motto { font-size: 9.5pt; color: #64748b; }
        .lh-meta { display: flex; justify-content: space-between; gap: 10mm; font-size: 8pt; color: #64748b; padding-top: 3mm; }
        .lh-body { flex: 1; }
        .lh-foot { border-top: 0.5pt solid #cbd5e1; padding-top: 4mm; text-align: center; font-size: 7.5pt; color: #94a3b8; }

        /* Banner: a full-bleed brand band across the top, name reversed out. */
        .design-banner { padding-top: 0; }
        .lh-band { margin: 0 -16mm; padding: 12mm 16mm 9mm; background: var(--brand); display: flex; justify-content: space-between; align-items: center; gap: 16mm; }
        .lh-band .lh-name { color: #fff; }
        .lh-band .lh-motto { color: rgba(255,255,255,.78); }
        /* The QR keeps its white quiet zone even on the coloured band. */
        .lh-qr-chip { background: #fff; padding: 1.5mm; flex-shrink: 0; }

        /* Sidebar: a slim brand bar carries the name; content indents past it. */
        .design-sidebar { position: relative; padding-left: 30mm; }
        .lh-bar { position: absolute; left: 0; top: 0; bottom: 0; width: 14mm; background: var(--brand); display: flex; justify-content: center; padding: 16mm 0; }
        .lh-bar-name { writing-mode: vertical-rl; transform: rotate(180deg); color: #fff; font-size: {{ $isA3 ? '17pt' : '13pt' }}; font-weight: 800; letter-spacing: 0.04em; white-space: nowrap; }
        .design-sidebar .lh-head { border-bottom-color: var(--brand); border-bottom-width: 0.75pt; }
        .lh-meta-col { font-size: 8pt; color: #64748b; line-height: 1.8; padding-top: 2mm; }

        /* Crest: everything centred between symmetric rules; the meta and the
           QR move to a centred footer so the page itself stays formal. */
        .lh-crest { text-align: center; border-top: 1.5pt solid #0f172a; border-bottom: 1.5pt solid #0f172a; padding: 6mm 0; }
        .lh-foot-crest { display: flex; flex-direction: column; align-items: center; gap: 1.5mm; }
        .lh-foot-crest svg { display: block; margin-bottom: 1.5mm; }

        /* Business card — bleed included, trim marks at 3mm */
        .card { width: 91mm; height: 61mm; padding: 3mm; page-break-after: always; }
        .card-inner { width: 85mm; height: 55mm; padding: 6mm; display: flex; flex-direction: column; justify-content: space-between; }
        .card-dark { background: #0f172a; color: #fff; align-items: center; justify-content: center; text-align: center; }
        .card-name { font-size: 12pt; font-weight: 800; letter-spacing: -0.02em; }
        .card-small { font-size: 7pt; color: #64748b; line-height: 1.6; }

        /* Stamp */
        .stamp-page { width: 210mm; min-height: 120mm; display: flex; align-items: center; justify-content: center; padding: 20mm; }
        .stamp { border: 4pt solid #1d4ed8; color: #1d4ed8; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
        .stamp-circular { width: 52mm; height: 52mm; border-radius: 50%; }
        .stamp-square { width: 52mm; height: 52mm; border-radius: 3mm; }
        .stamp-oval { width: 62mm; height: 42mm; border-radius: 50%; }
        .stamp-name { font-size: 8.5pt; font-weight: 800; text-transform: uppercase; letter-spacing: 0.03em; padding: 0 5mm; line-height: 1.2; }
        .stamp-rule { width: 14mm; height: 0.75pt; background: #1d4ed8; margin: 1.5mm 0; }
        .stamp-sub { font-size: 6pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; }

        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; display: flex; justify-content: center; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }

        /* Narrow screens scale the on-screen preview down to fit; @media print
           above is untouched, so paper keeps its true mm dimensions. */
        @media screen and (max-width: {{ $isA3 ? '1200px' : '880px' }}) {
            body { overflow-x: hidden; }
            .letterhead, .stamp-page { transform: scale({{ $isA3 ? '.5' : '.7' }}); transform-origin: top center; }
            .letterhead { margin-bottom: calc({{ $isA3 ? '420mm * -.5' : '297mm * -.3' }}); }
            .stamp-page { margin-bottom: calc(120mm * -.3); }
        }
        @media screen and (max-width: 560px) {
            .letterhead, .stamp-page { transform: scale({{ $isA3 ? '.3' : '.44' }}); }
            .letterhead { margin-bottom: calc({{ $isA3 ? '420mm * -.7' : '297mm * -.56' }}); }
            .stamp-page { margin-bottom: calc(120mm * -.56); }
        }
    </style>
</head>
<body>

@if ($asset === 'letterhead')
    <div class="sheet letterhead design-{{ $design }}" style="--brand: {{ $brand }}">
        @if ($design === 'banner')
            <div class="lh-band">
                <div>
                    <div class="lh-name">{{ $company->name }}</div>
                    @if ($company->motto)<div class="lh-motto">{{ $company->motto }}</div>@endif
                </div>
                <div class="lh-qr-chip">{!! $qrSvg !!}</div>
            </div>
            <div class="lh-meta">
                <span>{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</span>
                <span>{{ implode(' · ', array_filter([$phone, $company->email, $company->website])) }}</span>
            </div>
        @elseif ($design === 'sidebar')
            <div class="lh-bar"><div class="lh-bar-name">{{ $company->name }}</div></div>
            <div class="lh-head">
                <div>
                    @if ($company->motto)<div class="lh-motto" style="font-size: 11pt">{{ $company->motto }}</div>@endif
                    <div class="lh-meta-col">
                        <div>{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</div>
                        <div>{{ implode(' · ', array_filter([$phone, $company->email, $company->website])) }}</div>
                    </div>
                </div>
                <div>{!! $qrSvg !!}</div>
            </div>
        @elseif ($design === 'crest')
            <div class="lh-crest">
                <div class="lh-name">{{ $company->name }}</div>
                @if ($company->motto)<div class="lh-motto">{{ $company->motto }}</div>@endif
            </div>
        @else
            <div class="lh-head">
                <div>
                    <div class="lh-name">{{ $company->name }}</div>
                    @if ($company->motto)<div class="lh-motto">{{ $company->motto }}</div>@endif
                </div>
                <div>{!! $qrSvg !!}</div>
            </div>
            <div class="lh-meta">
                <span>{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</span>
                <span>{{ implode(' · ', array_filter([$phone, $company->email, $company->website])) }}</span>
            </div>
        @endif
        <div class="lh-body"></div>
        @if ($design === 'crest')
            <div class="lh-foot lh-foot-crest">
                {!! $qrSvg !!}
                <div>{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</div>
                <div>{{ implode(' · ', array_filter([$phone, $company->email, $company->website])) }}</div>
                <div>
                    {{ implode(' · ', array_filter([
                        $company->registration_number ? 'Reg. '.$company->registration_number : null,
                        'Generated with OPES360',
                    ])) }}
                </div>
            </div>
        @else
            <div class="lh-foot">
                {{ implode(' · ', array_filter([
                    $company->registration_number ? 'Reg. '.$company->registration_number : null,
                    $company->website,
                    'Generated with OPES360',
                ])) }}
            </div>
        @endif
    </div>

@elseif ($asset === 'card')
    {{-- Front --}}
    <div class="sheet card">
        <div class="card-inner">
            <div>
                <div class="card-name">{{ $company->name }}</div>
                @if ($company->motto)<div class="card-small">{{ $company->motto }}</div>@endif
            </div>
            <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:4mm">
                <div>
                    <div style="font-size:9pt; font-weight:700">{{ $name }}</div>
                    <div style="font-size:7pt; color:#64748b">{{ $title }}</div>
                    <div class="card-small" style="padding-top:2mm">
                        {{ $phone }}<br>{{ $company->email }}<br>{{ $company->website }}
                    </div>
                </div>
                <div>{!! $qrSvg !!}</div>
            </div>
        </div>
    </div>

    {{-- Back --}}
    <div class="sheet card">
        <div class="card-inner card-dark">
            <div class="card-name" style="font-size:14pt">{{ $company->name }}</div>
            <div style="font-size:7pt; color:#cbd5e1; padding-top:1mm">{{ $company->motto ?? $company->industry }}</div>
            <div style="background:#fff; padding:1.5mm; margin-top:3mm">{!! $qrSvg !!}</div>
            <div style="font-size:6pt; color:#94a3b8; padding-top:1.5mm">Scan to verify this business</div>
        </div>
    </div>

@else
    <div class="sheet stamp-page">
        <div class="stamp stamp-{{ $shape }}">
            <div class="stamp-name">{{ $company->name }}</div>
            <div class="stamp-rule"></div>
            <div class="stamp-sub">{{ $company->registration_number ?? $company->industry ?? 'Verified' }}</div>
            <div class="stamp-sub" style="font-weight:400; padding-top:1mm">{{ $company->city ?? $company->country }}</div>
        </div>
    </div>
@endif

<div class="print-bar no-print">
    <button onclick="window.print()">Print or Save as PDF</button>
</div>
</body>
</html>
