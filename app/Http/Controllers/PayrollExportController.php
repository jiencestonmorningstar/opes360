<?php

namespace App\Http\Controllers;

use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The livre de paie for a month, as a spreadsheet.
 *
 * A payroll a business cannot hand to its accountant is only half a payroll:
 * the CNPS and DGI declarations are filled in from this register, and nobody
 * is going to retype twenty payslips. One row per person, every base and every
 * charge in its own column — including the employer's, which never appear on
 * an employee's copy but are exactly what the CNPS return is built from.
 *
 * Written from the payslips' stored figures, so exporting an old month gives
 * what that month actually paid rather than what today's rates would produce.
 */
class PayrollExportController extends Controller
{
    public function register(PayrollRun $run): StreamedResponse
    {
        $filename = 'livre-de-paie-'.$run->period->format('Y-m');

        return response()->streamDownload(function () use ($run) {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Matricule', 'Nom', 'Emploi', 'N° CNPS', 'NIU',
                'Salaire de base', 'Indemnités imposables', 'Indemnités exonérées', 'Salaire brut',
                'Base imposable', 'Base CNPS plafonnée',
                'CNPS salarié', 'IRPP', 'CAC', 'CFC salarié', 'TDL', 'RAV',
                'Autres retenues', 'Avances', 'Total retenues', 'Net à payer',
                'CNPS pension patronale', 'CNPS prestations familiales', 'CNPS accidents du travail',
                'CFC patronal', 'FNE', 'Total charges patronales', 'Coût total',
                'Mode de paiement',
            ]);

            Payslip::query()
                ->where('payroll_run_id', $run->id)
                ->with('employee:id,first_name,last_name')
                ->chunk(200, function ($payslips) use ($out) {
                    foreach ($payslips as $slip) {
                        fputcsv($out, [
                            $slip->snapshot['number'] ?? '',
                            $slip->employeeName(),
                            $slip->jobTitle() ?? '',
                            $slip->snapshot['cnps_number'] ?? '',
                            $slip->snapshot['niu'] ?? '',
                            $slip->base_salary, $slip->taxable_allowances, $slip->exempt_allowances, $slip->gross,
                            $slip->taxable_gross, $slip->cnps_base,
                            $slip->cnps_employee, $slip->irpp, $slip->cac,
                            $slip->cfc_employee, $slip->tdl, $slip->rav,
                            $slip->other_deductions, $slip->advances, $slip->total_deductions, $slip->net_pay,
                            $slip->cnps_employer_pension, $slip->cnps_employer_family, $slip->cnps_employer_risk,
                            $slip->cfc_employer, $slip->fne, $slip->employer_charges, $slip->total_cost,
                            $slip->payment_method,
                        ]);
                    }
                });

            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }
}
