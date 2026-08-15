@php
    use App\Support\Money;

    $brand = $company->brandToken('primary', '#2563eb');
    $logoUrl = $company->logoUrl();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>VIP Card · {{ $membership->contact?->displayName() ?? 'Member' }}</title>
    {{--
        The same 85.6 × 54 mm sheet as the loyalty card, deliberately: a business
        printing both should not have to keep two card stocks, and a member who
        holds one of each expects them to sit together in a wallet.

        The dark treatment is the one difference. A VIP card is bought, and a
        card that looks identical to the free one it sits beside undersells what
        somebody paid for.
    --}}
    <style>
        @page { size: 91mm 61mm; margin: 0; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; background: #eef2f7; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { box-shadow: none !important; margin: 0 !important; } }

        .sheet { width: 91mm; padding: 3mm; background: #fff; margin: 24px auto; box-shadow: 0 4px 24px rgba(15,23,42,.12); }
        @media screen and (max-width: 380px) { .sheet { zoom: 0.85; } }
        @media screen and (max-width: 320px) { .sheet { zoom: 0.7; } }

        .card {
            width: 85mm; height: 55mm; padding: 5mm 6mm; border-radius: 3mm;
            background: #0f172a; color: #fff;
            display: flex; flex-direction: column; justify-content: space-between; overflow: hidden;
            position: relative;
            /* Browsers drop background imagery when printing to save ink, which
               would strip the card back to a flat rectangle on the one copy
               that matters — the one in somebody's hand. */
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        /* A wash of the business's own colour, so the card still reads as
           theirs rather than as a generic black rectangle. */
        .card::after {
            content: ''; position: absolute; inset: -40% -30% auto auto;
            width: 70mm; height: 70mm; border-radius: 50%;
            background: {{ $brand }}; opacity: 0.28;
        }
        .card > * { position: relative; z-index: 1; }

        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 4mm; }
        .logo { max-height: 8mm; max-width: 34mm; object-fit: contain; display: block; margin-bottom: 1mm; }
        .brand-name { font-size: 12pt; font-weight: 800; letter-spacing: -0.02em; }
        .card-label { font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.1em; color: rgba(255,255,255,.65); margin-top: 0.5mm; }
        .qr-chip { background: #fff; padding: 1.2mm; flex-shrink: 0; border-radius: 1mm; }
        .qr-chip svg { display: block; width: 15mm; height: 15mm; }

        .tier { font-size: 13pt; font-weight: 800; letter-spacing: -0.01em; }
        .saving { font-size: 7.5pt; color: rgba(255,255,255,.75); margin-top: 0.5mm; }
        .holder { font-size: 10pt; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .bottom { display: flex; justify-content: space-between; align-items: flex-end; gap: 4mm; }
        .number { font-variant-numeric: tabular-nums; font-size: 7.5pt; letter-spacing: 0.06em; color: rgba(255,255,255,.8); }
        .until { font-size: 6.5pt; color: rgba(255,255,255,.6); margin-top: 0.5mm; text-align: right; }

        .perks { margin-top: 3mm; font-size: 6.5pt; line-height: 1.4; color: rgba(255,255,255,.6); }

        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; display: flex; justify-content: center; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>

<div class="sheet">
    <div class="card">
        <div class="top">
            <div style="min-width:0">
                @if ($logoUrl)
                    <img class="logo" src="{{ $logoUrl }}" alt="">
                @endif
                <div class="brand-name">{{ $company->name }}</div>
                <div class="card-label">VIP Member</div>
            </div>

            {{-- Only when the membership has a token. Older ones predate card
                 issuing, and a blank white square reads as a printing fault. --}}
            @if ($qrSvg)
                <div class="qr-chip">{!! $qrSvg !!}</div>
            @endif
        </div>

        <div>
            <div class="tier">{{ $membership->tier_name }}</div>
            <div class="saving">
                {{ rtrim(rtrim(number_format((float) $membership->discount_percent, 2), '0'), '.') }}% off everything
            </div>
            <div class="holder" style="margin-top:2mm">{{ $membership->contact?->displayName() ?? 'Member' }}</div>
        </div>

        <div class="bottom">
            <div class="number">{{ $membership->card_number ?? '—' }}</div>
            <div class="until">
                Valid until<br>{{ $membership->ends_on?->format('j M Y') }}
            </div>
        </div>
    </div>
</div>

<div class="print-bar no-print">
    <button type="button" onclick="window.print()">Print card</button>
</div>

</body>
</html>
