@php
    use App\Support\Money;
    use Illuminate\Support\Facades\Storage;

    $phone = data_get($company->phones, 0);
    $isA3 = $size === 'a3';
    $brand = $company->brandToken('primary', '#1d4ed8');
    $logoUrl = $company->logo_path ? Storage::disk('public')->url($company->logo_path) : null;
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

        /* Letterhead */
        .letterhead { width: {{ $isA3 ? '297mm' : '210mm' }}; min-height: {{ $isA3 ? '420mm' : '297mm' }}; padding: 18mm 16mm; display: flex; flex-direction: column; }
        .lh-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16mm; border-bottom: 1.5pt solid #0f172a; padding-bottom: 6mm; }
        .lh-name { font-size: {{ $isA3 ? '26pt' : '20pt' }}; font-weight: 800; letter-spacing: -0.02em; }
        .lh-motto { font-size: 9.5pt; color: #64748b; }
        .lh-meta { display: flex; justify-content: space-between; gap: 10mm; font-size: 8pt; color: #64748b; padding-top: 3mm; }
        .lh-body { flex: 1; }
        .lh-foot { border-top: 0.5pt solid #cbd5e1; padding-top: 4mm; text-align: center; font-size: 7.5pt; color: #94a3b8; }

        /* Business card — bleed included, trim marks at 3mm. Dimensions are
           fixed mm and overflow is clipped: a long name truncates on its line
           rather than distorting the trim box. */
        .card { width: 91mm; height: 61mm; padding: 3mm; page-break-after: always; }
        .card-inner { width: 85mm; height: 55mm; padding: 6mm; display: flex; flex-direction: column; justify-content: space-between; overflow: hidden; }
        .card-dark { background: #0f172a; color: #fff; align-items: center; justify-content: center; text-align: center; }
        .card-name { font-size: 12pt; font-weight: 800; letter-spacing: -0.02em; }
        .card-small { font-size: 7pt; color: #64748b; line-height: 1.6; }
        .trunc { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Card designs restyle the inside of the same trim box. Every design
           keeps the verification QR: an unverifiable card defeats the module. */
        .card-bold { background: {{ $brand }}; color: #fff; }
        .card-bold .card-small { color: rgba(255,255,255,.78); }
        .qr-chip { background: #fff; padding: 1.5mm; flex-shrink: 0; }
        .qr-chip svg { display: block; width: 16mm; height: 16mm; }

        .card-minimal { padding: 5mm 6mm; }
        .card-minimal .rule { height: 0.35pt; background: #cbd5e1; flex-shrink: 0; }
        .min-name { font-size: 10.5pt; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; }
        .min-small { font-size: 6.5pt; color: #64748b; line-height: 1.7; letter-spacing: 0.02em; }
        .card-minimal .qr svg { display: block; width: 13mm; height: 13mm; }

        .card-split { padding: 0; flex-direction: row; }
        .split-left { width: 28mm; flex-shrink: 0; background: {{ $brand }}; color: #fff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2mm; padding: 4mm; }
        .split-logo { max-width: 18mm; max-height: 18mm; }
        .split-initials { width: 15mm; height: 15mm; border: 0.75pt solid rgba(255,255,255,.7); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13pt; font-weight: 800; }
        .split-right { flex: 1; min-width: 0; padding: 5mm; display: flex; flex-direction: column; justify-content: space-between; }
        .card-split .qr svg { display: block; width: 15mm; height: 15mm; }

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

        /* Screen preview only: the 91mm sheet (~344px) shrinks to fit narrow
           phones. Scoped to screen media, so paper keeps true millimetres. */
        @media screen and (max-width: 380px) { .card { zoom: 0.85; } }
        @media screen and (max-width: 320px) { .card { zoom: 0.7; } }
    </style>
</head>
<body>

@if ($asset === 'letterhead')
    <div class="sheet letterhead">
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
        <div class="lh-body"></div>
        <div class="lh-foot">
            {{ implode(' · ', array_filter([
                $company->registration_number ? 'Reg. '.$company->registration_number : null,
                $company->website,
                'Generated with OPES360',
            ])) }}
        </div>
    </div>

@elseif ($asset === 'card')
    {{-- Front --}}
    @if ($design === 'bold')
        <div class="sheet card">
            <div class="card-inner card-bold">
                <div>
                    <div class="card-name trunc">{{ $company->name }}</div>
                    @if ($company->motto)<div class="card-small trunc">{{ $company->motto }}</div>@endif
                </div>
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:4mm">
                    <div style="min-width:0">
                        <div class="trunc" style="font-size:9pt; font-weight:700">{{ $name }}</div>
                        <div class="card-small trunc">{{ $title }}</div>
                        <div class="card-small" style="padding-top:2mm">
                            {{ $phone }}<br>{{ $company->email }}<br>{{ $company->website }}
                        </div>
                    </div>
                    {{-- White chip keeps the QR scannable against the panel. --}}
                    <div class="qr-chip">{!! $qrSvg !!}</div>
                </div>
            </div>
        </div>

    @elseif ($design === 'minimal')
        <div class="sheet card">
            <div class="card-inner card-minimal">
                <div class="rule"></div>
                <div style="min-width:0">
                    <div class="min-name trunc">{{ $company->name }}</div>
                    @if ($company->motto)<div class="min-small trunc">{{ $company->motto }}</div>@endif
                </div>
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:4mm">
                    <div class="min-small" style="min-width:0">
                        <span class="trunc" style="display:block; font-size:7.5pt; font-weight:600; color:#0f172a">{{ $name }} · {{ $title }}</span>
                        {{ implode(' · ', array_filter([$phone, $company->email])) }}<br>
                        {{ implode(' · ', array_filter([$company->website, $company->city, $company->country])) }}
                    </div>
                    <div class="qr">{!! $qrSvg !!}</div>
                </div>
                <div class="rule"></div>
            </div>
        </div>

    @elseif ($design === 'split')
        <div class="sheet card">
            <div class="card-inner card-split">
                <div class="split-left">
                    @if ($logoUrl)
                        <img class="split-logo" src="{{ $logoUrl }}" alt="">
                    @else
                        <div class="split-initials">{{ $company->initials() }}</div>
                    @endif
                </div>
                <div class="split-right">
                    <div style="min-width:0">
                        <div class="card-name trunc" style="font-size:11pt">{{ $company->name }}</div>
                        @if ($company->motto)<div class="card-small trunc">{{ $company->motto }}</div>@endif
                    </div>
                    <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:3mm">
                        <div style="min-width:0">
                            <div class="trunc" style="font-size:8.5pt; font-weight:700">{{ $name }}</div>
                            <div class="card-small trunc">{{ $title }}</div>
                            <div class="card-small" style="padding-top:1.5mm">
                                {{ $phone }}<br>{{ $company->email }}<br>
                                <span class="trunc" style="display:block">{{ implode(' · ', array_filter([$company->address_line1, $company->city, $company->country])) }}</span>
                            </div>
                        </div>
                        <div class="qr">{!! $qrSvg !!}</div>
                    </div>
                </div>
            </div>
        </div>

    @else
        <div class="sheet card">
            <div class="card-inner card-classic">
                <div style="min-width:0">
                    <div class="card-name trunc">{{ $company->name }}</div>
                    @if ($company->motto)<div class="card-small trunc">{{ $company->motto }}</div>@endif
                </div>
                <div style="display:flex; justify-content:space-between; align-items:flex-end; gap:4mm">
                    <div style="min-width:0">
                        <div class="trunc" style="font-size:9pt; font-weight:700">{{ $name }}</div>
                        <div class="trunc" style="font-size:7pt; color:#64748b">{{ $title }}</div>
                        <div class="card-small" style="padding-top:2mm">
                            {{ $phone }}<br>{{ $company->email }}<br>{{ $company->website }}
                        </div>
                    </div>
                    <div>{!! $qrSvg !!}</div>
                </div>
            </div>
        </div>
    @endif

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
