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

        /*
         * Two audiences, two type scales.
         *
         * This page is read on a screen and then printed, and those want
         * different things. A4 is sized in points because that is what lands
         * correctly on paper; a phone reading the same page at 10.5pt gets
         * roughly 14px, which is too small to read a total off. So the screen
         * gets 17px and print reverts to points below.
         *
         * `--fs` carries the base so every element scales from one number
         * rather than each being overridden twice.
         */
        :root { --fs: 17px; --fs-small: 14px; }
        body {
            font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, sans-serif;
            font-size: var(--fs); color: #0f172a; line-height: 1.5;
        }
        /* On screen the sheet gives way to the viewport; overflow-wrap keeps a
           long unbroken value (an email, a reference) from forcing a wider box.
           The fixed print bar needs the body padding so it covers nothing. */
        .sheet { width: 100%; max-width: 178mm; margin: 0 auto; padding: 24px 8px 96px; overflow-wrap: break-word; position: relative; }

        @media print {
            /* Back to paper units: the point sizes are what fit A4. */
            :root { --fs: 10.5pt; --fs-small: 8.5pt; }
            body { line-height: 1.45; }
            .sheet { padding: 0; }
            .no-print { display: none !important; }
        }

        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        /* Flex items refuse to shrink below their content unless told to,
           which is how a long business name pushes the sheet sideways. */
        header > div, .parties > div, footer > div { min-width: 0; }
        .brand-name { font-size: calc(var(--fs) * 1.62); font-weight: 800; letter-spacing: -0.02em; }
        /* Bounded in both directions: a business uploads whatever it has, and a
           tall logo left unconstrained pushes the whole sheet down the page.
           `object-fit: contain` keeps a wide banner and a square mark both
           undistorted within that box. */
        .letterhead-logo { max-height: 20mm; max-width: 60mm; object-fit: contain; display: block; margin-bottom: 8px; }
        .letterhead-center { text-align: center; }
        .letterhead-center .letterhead-logo { margin-left: auto; margin-right: auto; }
        .muted { color: #64748b; }
        .small { font-size: var(--fs-small); }
        .doc-type { font-size: calc(var(--fs) * 1.24); font-weight: 800; text-transform: uppercase; letter-spacing: 0.06em; color: #2563eb; text-align: right; }
        .doc-number { font-size: calc(var(--fs) * 1.05); font-weight: 700; text-align: right; }

        /* Notes are a document inside the document — a scope, terms, exclusions.
           Given real headings and lists they read as one; run together as a
           single block they do not, and a quotation nobody reads is a quotation
           nobody signs. `break-inside: avoid` keeps a heading with what follows
           it rather than orphaned at a page break. */
        .notes-body { font-size: var(--fs-small); line-height: 1.55; }
        .notes-body > * { margin: 0 0 6px; }
        .notes-h { font-size: calc(var(--fs-small) * 0.94); font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
                   margin: 12px 0 5px; break-after: avoid; }
        .notes-body > .notes-h:first-child { margin-top: 0; }
        .notes-rule { border: 0; border-top: 1px solid #e2e8f0; margin: 10px 0; }
        .notes-body ul, .notes-body ol { margin: 0 0 6px; padding-left: 16px; }
        .notes-body li { margin-bottom: 2px; break-inside: avoid; }

        .parties { display: flex; justify-content: space-between; gap: 24px; margin-top: 28px; }
        .label { font-size: calc(var(--fs-small) * 0.88); font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #94a3b8; margin-bottom: 4px; }
        .party-name { font-weight: 700; font-size: calc(var(--fs) * 1.05); }

        table { width: 100%; border-collapse: collapse; margin-top: 26px; }
        thead th {
            text-align: left; font-size: calc(var(--fs-small) * 0.94); font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.06em; color: #64748b; padding: 0 8px 8px;
            border-bottom: 1.5pt solid #0f172a;
        }
        tbody td { padding: 9px 8px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; overflow-wrap: anywhere; }
        /* A row split across a page break reads as two half-entries. */
        tbody tr { page-break-inside: avoid; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }

        .totals { margin-top: 14px; margin-left: auto; width: 62mm; max-width: 100%; page-break-inside: avoid; }
        .totals .row { display: flex; justify-content: space-between; padding: 4px 8px; }
        .totals .grand { border-top: 1.5pt solid #0f172a; margin-top: 4px; padding-top: 8px; font-weight: 800; font-size: calc(var(--fs) * 1.14); }

        /* The QR footer and the signature block must land on paper whole. */
        footer { display: flex; justify-content: space-between; align-items: flex-end; gap: 24px; margin-top: 40px; page-break-inside: avoid; }
        .verify { display: flex; gap: 12px; align-items: center; min-width: 0; }
        .verify svg { display: block; flex-shrink: 0; }
        .verify .small { overflow-wrap: anywhere; }
        .sign { text-align: center; }
        .sign .line { width: 52mm; max-width: 100%; border-bottom: 0.75pt solid #0f172a; margin-bottom: 4px; height: 40px; }

        .powered { margin-top: 28px; text-align: center; font-size: calc(var(--fs-small) * 0.88); color: #94a3b8; }
        .print-bar {
            position: fixed; inset: auto 0 0 0; background: #0f172a; color: #fff;
            display: flex; justify-content: center; gap: 16px; padding: 12px;
        }
        .print-bar button {
            background: #2563eb; color: #fff; border: 0; border-radius: 8px;
            padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer;
        }

        /*
         * The watermark: the company's own logo, very faint, behind the page.
         *
         * `position: fixed` rather than absolute so it repeats on every printed
         * page instead of appearing once on page one and leaving a three-page
         * quotation unmarked after it.
         *
         * `print-color-adjust: exact` is load-bearing. Browsers drop background
         * imagery when printing to save ink, which would silently remove the
         * watermark from precisely the copy that matters — the one on paper.
         */
        .watermark {
            position: fixed; inset: 0; z-index: 0; pointer-events: none;
            display: flex; align-items: center; justify-content: center;
        }
        .watermark img {
            width: 62%; max-width: 120mm; opacity: 0.05;
            transform: rotate(-24deg);
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        /* Everything else sits above it. Without this the watermark would
           paint over the table rather than under it. */
        .sheet > * { position: relative; z-index: 1; }

        /*
         * Phones.
         *
         * The sheet is an A4 facsimile — millimetre widths, a five-column line
         * table, side-by-side parties and footer. None of that fits 390px, and
         * the result was a page that scrolled sideways with the total off the
         * edge. Screen only: `@media print` below is untouched, so the paper
         * output is exactly what it was.
         */
        @media screen and (max-width: 640px) {
            :root { --fs: 17px; --fs-small: 14px; }

            header, .parties, footer { flex-direction: column; gap: 16px; }
            .doc-type, .doc-number { text-align: left; }

            /* The line table becomes one block per line: the column headings
               are dropped and each cell carries its own label, which reads far
               better than four columns crushed into a phone's width. */
            table thead { display: none; }
            table, tbody, tbody tr, tbody td { display: block; width: 100%; }
            tbody tr { padding: 12px 0; border-bottom: 1px solid #e2e8f0; }
            tbody td { border: 0; padding: 2px 0; }
            tbody td[data-label]::before {
                content: attr(data-label) ' ';
                font-weight: 700; color: #64748b; text-transform: uppercase;
                font-size: calc(var(--fs-small) * 0.88); letter-spacing: 0.06em;
            }
            .num { text-align: left; }

            .totals { width: 100%; margin-left: 0; }
            .sign .line, .totals { max-width: none; }
            .watermark img { width: 80%; }
        }
    </style>
</head>
<body>
<div class="sheet">
    {{-- Behind everything, on every page. Only when the business has a logo:
         a watermark of nothing is an empty grey smudge. --}}
    @if ($company->logoUrl())
        <div class="watermark" aria-hidden="true">
            <img src="{{ $company->logoUrl() }}" alt="">
        </div>
    @endif

    <header>
        <div>
            {{-- Shared with every other printed document, and read from the
                 company at render time so a changed logo or address reaches
                 documents already issued. See the partial. --}}
            @include('print.partials.letterhead', ['company' => $company])

            @if ($company->tax_regime || $company->tax_centre)
                <div class="muted small">
                    {{ implode(' · ', array_filter([
                        \App\Enums\TaxRegime::tryFrom((string) $company->tax_regime)?->label(),
                        $company->tax_centre ? 'CDI '.$company->tax_centre : null,
                    ])) }}
                </div>
            @endif
            @if ($company->capital_social)
                <div class="muted small">Capital social {{ Money::format($company->capital_social, $company->currency, false) }}</div>
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
            @if ($document->contact?->tax_id)
                {{-- A business buyer needs its own NIU on the invoice to deduct
                     the TVA it was charged. --}}
                <div class="muted small">NIU {{ $document->contact->tax_id }}</div>
            @endif
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
                {{-- `data-label` is what lets the table become one block per
                     line on a phone: the headings are hidden there, so each
                     cell states its own. Ignored entirely on paper. --}}
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="num" data-label="Qty">{{ rtrim(rtrim($line->quantity, '0'), '.') }}</td>
                    <td class="num" data-label="Unit price">{{ Money::format($line->unit_price, $document->currency) }}</td>
                    <td class="num" data-label="Total">{{ Money::format($line->line_total, $document->currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div class="row"><span class="muted">Total HT</span><span class="num">{{ Money::format($document->subtotal, $document->currency) }}</span></div>
        @if ((float) $document->discount_total > 0)
            <div class="row"><span class="muted">Remise</span><span class="num">−{{ Money::format($document->discount_total, $document->currency) }}</span></div>
        @endif
        @if ($company->vat_registered)
            <div class="row">
                <span class="muted">TVA {{ rtrim(rtrim(number_format((float) $company->vat_rate, 2, '.', ''), '0'), '.') }}%</span>
                <span class="num">{{ Money::format($document->tax_total, $document->currency) }}</span>
            </div>
        @endif
        <div class="row grand">
            <span>{{ $company->vat_registered ? 'Total TTC' : 'Total' }}</span>
            <span class="num">{{ Money::format($document->total, $document->currency) }}</span>
        </div>
        @if ((float) $document->amount_paid > 0)
            <div class="row"><span class="muted">Paid</span><span class="num">−{{ Money::format($document->amount_paid, $document->currency) }}</span></div>
            <div class="row" style="font-weight:700"><span>Balance due</span><span class="num">{{ Money::format($document->balance, $document->currency) }}</span></div>
        @endif
    </div>

    {{-- Arrêté en toutes lettres. The point is tamper evidence: changing a
         digit is easy, changing the digits and the sentence that agrees with
         them is not. --}}
    <div style="margin-top:16px; clear:both">
        <div class="small">
            Arrêtée la présente {{ strtolower($document->type->label()) }} à la somme de
            <strong>{{ \App\Support\AmountInWords::forCurrency((float) $document->total, $document->currency) }}</strong>.
        </div>
        @unless ($company->vat_registered)
            {{-- Silence would read like a forgotten tax line rather than a
                 business that does not charge one. --}}
            <div class="muted small" style="margin-top:4px">{{ \App\Support\Vat::exemptionNotice($company) }}</div>
        @endunless
    </div>

    @if ($document->notes)
        {{-- Parsed back into the structure it was written with — headings,
             lists, rules — rather than printed as one pre-wrapped block. See
             App\Support\DocumentNotes for the conventions it reads. --}}
        <div style="margin-top:22px" class="notes">
            <div class="label">Notes</div>
            <div class="notes-body">
                @foreach (\App\Support\DocumentNotes::parse($document->notes) as $block)
                    @switch($block['type'])
                        @case('heading')
                            <p class="notes-h">{{ $block['text'] }}</p>
                            @break

                        @case('rule')
                            <hr class="notes-rule">
                            @break

                        @case('bullets')
                            <ul>
                                @foreach ($block['items'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                            @break

                        @case('numbers')
                            <ol>
                                @foreach ($block['items'] as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ol>
                            @break

                        @default
                            <p>{{ $block['text'] }}</p>
                    @endswitch
                @endforeach
            </div>
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
    <script @cspNonce>window.addEventListener('load', function () { window.print(); });</script>
@endif
</body>
</html>
