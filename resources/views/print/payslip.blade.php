@php
    use App\Support\Money;

    $currency = $payslip->currency ?: ($company->currency ?? 'XAF');
    $money = fn ($amount) => Money::format((float) $amount, $currency, false);

    $earnings = $payslip->lines->where('kind', 'earning');
    $deductions = $payslip->lines->where('kind', 'deduction');
    $employer = $payslip->lines->where('kind', 'employer');

    /*
     * The sheet is in French because that is the language a bulletin de paie
     * is read and audited in here, so the month is too — the app's own locale
     * would print "July 2026" on an otherwise French document.
     */
    $period = $payslip->run?->period?->copy()->locale('fr');
@endphp

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Bulletin de paie · {{ $payslip->employeeName() }}</title>
    <style>
        /* Paper-white regardless of the reader's colour scheme: a payslip is a
           document, and a dark-mode preference must not follow it onto A4. */
        @page { size: A4; margin: 14mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, sans-serif; font-size: 10pt; color: #000; background: #fff; line-height: 1.45; }
        .sheet { width: min(182mm, calc(100vw - 24px)); margin: 16px auto 96px; }
        @media print { .sheet { width: auto; margin: 0; } .no-print { display: none !important; } }

        .head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 10px; }
        .name { font-weight: 800; font-size: 14pt; letter-spacing: -0.02em; }
        .muted { color: #444; }
        .small { font-size: 8.5pt; }
        .title { text-align: center; font-weight: 800; font-size: 12pt; letter-spacing: 0.04em; text-transform: uppercase; margin: 14px 0 4px; }
        .period { text-align: center; font-size: 10.5pt; margin-bottom: 14px; }

        .who { display: flex; gap: 16px; border: 1px solid #999; padding: 8px 10px; margin-bottom: 14px; }
        .who > div { flex: 1; min-width: 0; }
        .who dt { font-size: 8pt; color: #444; text-transform: uppercase; letter-spacing: 0.03em; }
        .who dd { font-weight: 600; overflow-wrap: anywhere; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; text-align: right; }
        th:first-child, td:first-child { text-align: left; }
        thead th { border-bottom: 1px solid #000; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.03em; }
        tbody td { border-bottom: 1px solid #ddd; }
        .num { font-variant-numeric: tabular-nums; white-space: nowrap; }
        .section { margin-top: 14px; page-break-inside: avoid; }
        .section h2 { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
        .total td { border-bottom: 0; border-top: 1px solid #000; font-weight: 700; }

        .net { margin-top: 16px; border: 2px solid #000; padding: 10px 12px; display: flex; justify-content: space-between; align-items: baseline; page-break-inside: avoid; }
        .net .label { font-weight: 800; font-size: 11pt; text-transform: uppercase; letter-spacing: 0.04em; }
        .net .value { font-weight: 800; font-size: 16pt; font-variant-numeric: tabular-nums; }

        .foot { margin-top: 16px; border-top: 1px solid #999; padding-top: 8px; font-size: 8pt; color: #444; }
        .sign { margin-top: 22px; display: flex; justify-content: space-between; gap: 32px; page-break-inside: avoid; }
        .sign > div { flex: 1; border-top: 1px solid #000; padding-top: 4px; font-size: 8.5pt; }

        .print-bar { position: fixed; inset: auto 0 0 0; background: #0f172a; display: flex; justify-content: center; padding: 12px; }
        .print-bar button { background: #2563eb; color: #fff; border: 0; border-radius: 8px; padding: 10px 26px; font: inherit; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>
<div class="sheet">

    <div class="head">
        <div>
            <div class="name">{{ $company->name }}</div>
            <div class="small muted">
                @if ($company->address){{ $company->address }}<br>@endif
                @if ($company->cnps_employer_number)CNPS employeur : {{ $company->cnps_employer_number }}<br>@endif
                @if ($company->registration_number)RCCM : {{ $company->registration_number }}@endif
            </div>
        </div>
        <div class="small muted" style="text-align:right">
            <div>N° {{ $payslip->number }}</div>
            <div>Édité le {{ now()->format('d/m/Y') }}</div>
        </div>
    </div>

    <div class="title">Bulletin de paie</div>
    <div class="period">{{ $period?->translatedFormat('F Y') }}</div>

    <div class="who">
        <div>
            <dt>Salarié</dt>
            <dd>{{ $payslip->employeeName() }}</dd>
            @if ($payslip->jobTitle())
                <dt style="margin-top:6px">Emploi</dt>
                <dd>{{ $payslip->jobTitle() }}</dd>
            @endif
        </div>
        <div>
            <dt>Matricule</dt>
            <dd>{{ $payslip->snapshot['number'] ?? '—' }}</dd>
            <dt style="margin-top:6px">N° CNPS</dt>
            <dd>{{ $payslip->snapshot['cnps_number'] ?? '—' }}</dd>
        </div>
        <div>
            <dt>Embauche</dt>
            <dd>{{ isset($payslip->snapshot['hired_on']) ? \Illuminate\Support\Carbon::parse($payslip->snapshot['hired_on'])->format('d/m/Y') : '—' }}</dd>
            <dt style="margin-top:6px">Contrat</dt>
            <dd>{{ strtoupper($payslip->snapshot['contract_type'] ?? '—') }}</dd>
        </div>
    </div>

    <div class="section">
        <h2>Gains</h2>
        <table>
            <thead>
                <tr><th>Désignation</th><th>Base</th><th>Taux</th><th>Montant</th></tr>
            </thead>
            <tbody>
                @foreach ($earnings as $line)
                    <tr>
                        <td>{{ $line->label }}</td>
                        <td class="num">{{ $line->base !== null ? $money($line->base) : '' }}</td>
                        <td class="num">{{ $line->rateLabel() }}</td>
                        <td class="num">{{ $money($line->amount) }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td>Salaire brut</td><td></td><td></td>
                    <td class="num">{{ $money($payslip->gross) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="section">
        <h2>Retenues</h2>
        <table>
            <thead>
                <tr><th>Désignation</th><th>Base</th><th>Taux</th><th>Montant</th></tr>
            </thead>
            <tbody>
                @forelse ($deductions as $line)
                    <tr>
                        <td>{{ $line->label }}</td>
                        <td class="num">{{ $line->base !== null ? $money($line->base) : '' }}</td>
                        <td class="num">{{ $line->rateLabel() }}</td>
                        <td class="num">{{ $money($line->amount) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">Aucune retenue</td></tr>
                @endforelse
                <tr class="total">
                    <td>Total des retenues</td><td></td><td></td>
                    <td class="num">{{ $money($payslip->total_deductions) }}</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="net">
        <span class="label">Net à payer</span>
        <span class="value">{{ $money($payslip->net_pay) }}</span>
    </div>

    {{-- Charges patronales are shown because the employee is entitled to see
         what is paid on their behalf, and because the employer needs the
         figure to reconcile the CNPS declaration. Neither is withheld. --}}
    @if ($employer->isNotEmpty())
        <div class="section">
            <h2>Charges patronales — non retenues sur le salaire</h2>
            <table>
                <thead>
                    <tr><th>Désignation</th><th>Base</th><th>Taux</th><th>Montant</th></tr>
                </thead>
                <tbody>
                    @foreach ($employer as $line)
                        <tr>
                            <td>{{ $line->label }}</td>
                            <td class="num">{{ $line->base !== null ? $money($line->base) : '' }}</td>
                            <td class="num">{{ $line->rateLabel() }}</td>
                            <td class="num">{{ $money($line->amount) }}</td>
                        </tr>
                    @endforeach
                    <tr class="total">
                        <td>Coût total employeur</td><td></td><td></td>
                        <td class="num">{{ $money($payslip->total_cost) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif

    <div class="sign">
        <div>L'employeur</div>
        <div>Le salarié — pour acquit</div>
    </div>

    <div class="foot">
        Bulletin établi conformément aux dispositions du Code du travail et du Code général des impôts.
        À conserver sans limitation de durée.
        @if ($payslip->paid_on) Payé le {{ $payslip->paid_on->format('d/m/Y') }}. @endif
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
