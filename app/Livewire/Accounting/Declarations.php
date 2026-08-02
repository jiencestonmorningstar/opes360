<?php

namespace App\Livewire\Accounting;

use App\Services\Accounting\TaxDeclarations;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The month's returns, worked out from the books.
 *
 * Everything here is due on the fifteenth, every month, whether or not anybody
 * has added it up — which is the whole reason the screen exists. It is a
 * worksheet to copy onto the official forms, and it says so at the top rather
 * than letting anyone discover it later.
 */
class Declarations extends Component
{
    /** The month being declared, as YYYY-MM. */
    #[Url(as: 'month')]
    public string $month = '';

    public function mount(): void
    {
        Gate::authorize('accounting.view');

        // Last month by default: the one that is actually due. A declaration
        // screen opening on the current, unfinished month invites somebody to
        // file half of it.
        $this->month = $this->month ?: now()->subMonth()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->period()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $next = $this->period()->addMonth();

        // Never past the current month: there is nothing to declare about a
        // month that has not started.
        if ($next->startOfMonth()->lessThanOrEqualTo(now()->startOfMonth())) {
            $this->month = $next->format('Y-m');
        }
    }

    /**
     * The TVA worksheet as a spreadsheet, with the entries behind it.
     *
     * The detail rows matter more than the totals: a figure that looks wrong
     * is only useful if the document that produced it can be found, and
     * "collected 1 240 500" on its own sends an accountant back to the ledger.
     */
    public function exportVat(TaxDeclarations $declarations): StreamedResponse
    {
        Gate::authorize('accounting.export');

        $company = app(CurrentCompany::class)->get();
        [$from, $to] = $this->window();

        $vat = $declarations->vat($company, $from, $to);
        $filename = 'tva-'.$this->month.'.csv';

        return response()->streamDownload(function () use ($vat, $from, $to) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Déclaration de TVA — période', $from, 'au', $to]);
            fputcsv($out, []);
            fputcsv($out, ['Chiffre d\'affaires (HT)', $vat['turnover']]);
            fputcsv($out, ['dont opérations taxables', $vat['taxed_turnover']]);
            fputcsv($out, ['dont opérations non taxées', $vat['exempt_turnover']]);
            fputcsv($out, ['TVA facturée (443)', $vat['collected']]);
            fputcsv($out, ['TVA récupérable (445)', $vat['deductible']]);
            fputcsv($out, ['TVA due', $vat['due']]);
            fputcsv($out, ['Crédit de TVA à reporter', $vat['credit']]);
            fputcsv($out, []);
            fputcsv($out, ['Détail des écritures']);
            fputcsv($out, ['Date', 'Journal', 'Pièce', 'Libellé', 'Compte', 'Nature', 'Montant']);

            foreach ($vat['lines'] as $line) {
                fputcsv($out, [
                    $line['date']?->toDateString(),
                    $line['journal'],
                    $line['reference'],
                    $line['narration'],
                    $line['account'],
                    $line['kind'] === 'deductible' ? 'Récupérable' : 'Facturée',
                    abs($line['amount']),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /** The CNPS and DGI totals on wages, as one sheet. */
    public function exportPayroll(TaxDeclarations $declarations): StreamedResponse
    {
        Gate::authorize('accounting.export');

        $company = app(CurrentCompany::class)->get();
        [$from, $to] = $this->window();

        $payroll = $declarations->payroll($company, $from, $to);
        $filename = 'declarations-sociales-'.$this->month.'.csv';

        return response()->streamDownload(function () use ($payroll, $from, $to) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Déclarations sur salaires — période', $from, 'au', $to]);
            fputcsv($out, ['Effectif déclaré', $payroll['headcount']]);
            fputcsv($out, []);
            fputcsv($out, ['CNPS']);
            fputcsv($out, ['Assiette plafonnée', $payroll['cnps_base']]);
            fputcsv($out, ['Pension — part salariale', $payroll['cnps_employee']]);
            fputcsv($out, ['Pension — part patronale', $payroll['cnps_employer_pension']]);
            fputcsv($out, ['Prestations familiales', $payroll['cnps_employer_family']]);
            fputcsv($out, ['Accidents du travail', $payroll['cnps_employer_risk']]);
            fputcsv($out, ['Total dû à la CNPS', $payroll['cnps_total']]);
            fputcsv($out, []);
            fputcsv($out, ['DGI']);
            fputcsv($out, ['Salaire brut', $payroll['gross']]);
            fputcsv($out, ['Base imposable', $payroll['taxable_gross']]);
            fputcsv($out, ['IRPP', $payroll['irpp']]);
            fputcsv($out, ['CAC', $payroll['cac']]);
            fputcsv($out, ['CFC — part salariale', $payroll['cfc_employee']]);
            fputcsv($out, ['CFC — part patronale', $payroll['cfc_employer']]);
            fputcsv($out, ['TDL', $payroll['tdl']]);
            fputcsv($out, ['RAV', $payroll['rav']]);
            fputcsv($out, ['FNE', $payroll['fne']]);
            fputcsv($out, ['Total dû à la DGI', $payroll['dgi_total']]);
            fputcsv($out, []);
            fputcsv($out, ['Net versé au personnel', $payroll['net_paid']]);
            fputcsv($out, ['Coût total employeur', $payroll['total_cost']]);

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render(TaxDeclarations $declarations): View
    {
        $company = app(CurrentCompany::class)->get();
        [$from, $to] = $this->window();

        return view('livewire.accounting.declarations', [
            'from' => $from,
            'to' => $to,
            'label' => $this->period()->translatedFormat('F Y'),
            'chartIsReady' => $company !== null && $declarations->chartIsReady($company),
            'vat' => $company ? $declarations->vat($company, $from, $to) : null,
            'payroll' => $company ? $declarations->payroll($company, $from, $to) : null,
            'vatRegistered' => (bool) $company?->vat_registered,
            'atCurrentMonth' => $this->period()->startOfMonth()->equalTo(now()->startOfMonth()),
            /*
             * When the return falls due. Both the TVA and the payroll levies
             * are due by the 15th of the following month; a business filing on
             * the 20th because nobody told it the date is the failure this
             * whole screen exists to prevent.
             */
            'dueOn' => $this->period()->addMonth()->startOfMonth()->addDays(14),
            'currency' => $company?->currency ?? 'XAF',
        ])->layout('components.layouts.app', [
            'title' => 'Declarations',
            'active' => 'accounting',
        ]);
    }

    protected function period(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
    }

    /** @return array{0: string, 1: string} */
    protected function window(): array
    {
        $period = $this->period();

        return [$period->toDateString(), $period->endOfMonth()->toDateString()];
    }
}
