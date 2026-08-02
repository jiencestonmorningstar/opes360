<?php

namespace App\Services\Payroll;

/**
 * Cameroonian payroll arithmetic.
 *
 * ── Nothing here is a constant ───────────────────────────────────────────
 *
 * Every rate, ceiling and band comes from the array this was constructed with
 * — normally config/payroll.php, but a payroll run that was approved last year
 * hands back the rates it recorded at the time, and this class computes the
 * same answer it computed then. That is the entire reason it holds no numbers
 * of its own.
 *
 * ── The three bases ─────────────────────────────────────────────────────
 *
 * The commonest mistake in a hand-built payroll is treating "salary" as one
 * figure. It is three:
 *
 *   the gross            everything earned, what the employee thinks they earn;
 *   the taxable gross    minus allowances the code exempts — the base for the
 *                        IRPP, the CFC and the FNE;
 *   the CNPS base        the contributable salary, capped at 750 000 F for
 *                        pension and family allowances but NOT for
 *                        occupational risk, which follows the whole of it.
 *
 * They coincide for a shop assistant on a flat salary and diverge the moment
 * anyone gets a transport allowance or earns above the ceiling.
 *
 * ── Why the IRPP is annualised ──────────────────────────────────────────
 *
 * The scale is annual by law. Twelve times the month, taxed, divided by
 * twelve, is the standard monthly withholding and agrees with the annual
 * reading the DGI reconciles against. Applying a twelfth of each band to the
 * month gives the same answer for a steady salary and a different one in the
 * month a bonus lands — and the second answer is the wrong one.
 */
class PayrollCalculator
{
    /** @param array<string, mixed> $rates */
    public function __construct(protected array $rates) {}

    public static function fromConfig(): self
    {
        return new self(config('payroll'));
    }

    /** The rates this instance used, for a run to record alongside its payslips. */
    public function rates(): array
    {
        return $this->rates;
    }

    public function compute(PayrollInput $in): PayrollResult
    {
        $base = $this->round($in->baseSalary);
        $overtime = $this->round($in->overtime);

        $gross = $this->round($base + $in->totalAllowances() + $overtime);
        $taxableGross = $this->round($base + $in->taxableAllowances() + $overtime);
        $cnpsUncapped = $this->round($base + $in->cnpsAllowances() + $overtime);

        $ceiling = (float) $this->get('cnps.ceiling', 750000);
        $cnpsBase = $this->round(min($cnpsUncapped, $ceiling));

        $lines = [];

        // ── Earnings ────────────────────────────────────────────────────
        $lines[] = $this->line('earning', 'base', 'Salaire de base', $base);

        foreach ($in->allowances as $allowance) {
            $lines[] = $this->line(
                'earning',
                $allowance['code'] ?? 'allowance',
                $allowance['label'],
                $this->round((float) $allowance['amount']),
            );
        }

        if ($overtime > 0) {
            $lines[] = $this->line('earning', 'overtime', 'Heures supplémentaires', $overtime);
        }

        // ── CNPS, employee side ─────────────────────────────────────────
        $pensionRate = (float) $this->get('cnps.pension.employee', 0.042);
        $cnpsEmployee = $this->round($cnpsBase * $pensionRate);

        // ── IRPP and its centimes additionnels ──────────────────────────
        $irpp = $this->irppFor($taxableGross, $cnpsEmployee);
        $cac = $this->round($irpp * (float) $this->get('irpp.cac', 0.10));

        // ── The rest of the withholdings ────────────────────────────────
        $cfcEmployee = $this->round($taxableGross * (float) $this->get('cfc.employee', 0.01));

        $tdl = $in->withholdTdl && $this->get('tdl.enabled', true)
            ? $this->banded('tdl', $base, $gross, $taxableGross)
            : 0.0;

        $rav = $in->withholdRav && $this->get('rav.enabled', true)
            ? $this->banded('rav', $base, $gross, $taxableGross)
            : 0.0;

        $other = $this->round($in->totalDeductions());
        $advances = $this->round($in->advances);

        if ($cnpsEmployee > 0) {
            $lines[] = $this->line('deduction', 'cnps', 'CNPS — pension vieillesse', $cnpsEmployee, $cnpsBase, $pensionRate);
        }

        if ($irpp > 0) {
            $lines[] = $this->line('deduction', 'irpp', 'IRPP', $irpp, $taxableGross);
        }

        if ($cac > 0) {
            $lines[] = $this->line('deduction', 'cac', 'Centimes additionnels communaux', $cac, $irpp, (float) $this->get('irpp.cac', 0.10));
        }

        if ($cfcEmployee > 0) {
            $lines[] = $this->line('deduction', 'cfc', 'Crédit Foncier', $cfcEmployee, $taxableGross, (float) $this->get('cfc.employee', 0.01));
        }

        if ($tdl > 0) {
            $lines[] = $this->line('deduction', 'tdl', 'Taxe de développement local', $tdl);
        }

        if ($rav > 0) {
            $lines[] = $this->line('deduction', 'rav', 'Redevance audiovisuelle', $rav);
        }

        foreach ($in->deductions as $deduction) {
            $lines[] = $this->line(
                'deduction',
                $deduction['code'] ?? 'other',
                $deduction['label'],
                $this->round((float) $deduction['amount']),
            );
        }

        if ($advances > 0) {
            $lines[] = $this->line('deduction', 'advance', 'Avance sur salaire', $advances);
        }

        $totalDeductions = $this->round($cnpsEmployee + $irpp + $cac + $cfcEmployee + $tdl + $rav + $other + $advances);
        $net = $this->round($gross - $totalDeductions);

        // ── The employer's own bill ─────────────────────────────────────
        $employerPensionRate = (float) $this->get('cnps.pension.employer', 0.042);
        $familyRate = (float) $this->get("cnps.family_allowances.{$in->familyRegime}", $this->get('cnps.family_allowances.general', 0.07));
        $riskRate = (float) $this->get("cnps.occupational_risk.groups.{$in->riskGroup}.rate",
            $this->get('cnps.occupational_risk.groups.a.rate', 0.0175));

        $employerPension = $this->round($cnpsBase * $employerPensionRate);
        $employerFamily = $this->round($cnpsBase * $familyRate);
        // Uncapped, deliberately: the risk does not stop at the ceiling.
        $employerRisk = $this->round($cnpsUncapped * $riskRate);
        $cfcEmployer = $this->round($taxableGross * (float) $this->get('cfc.employer', 0.015));
        $fne = $this->round($taxableGross * (float) $this->get('fne.employer', 0.01));

        foreach ([
            ['cnps_pension_er', 'CNPS — pension (part patronale)', $employerPension, $cnpsBase, $employerPensionRate],
            ['cnps_family', 'CNPS — prestations familiales', $employerFamily, $cnpsBase, $familyRate],
            ['cnps_risk', 'CNPS — accidents du travail', $employerRisk, $cnpsUncapped, $riskRate],
            ['cfc_er', 'Crédit Foncier (part patronale)', $cfcEmployer, $taxableGross, (float) $this->get('cfc.employer', 0.015)],
            ['fne', 'Fonds National de l\'Emploi', $fne, $taxableGross, (float) $this->get('fne.employer', 0.01)],
        ] as [$code, $label, $amount, $lineBase, $rate]) {
            if ($amount > 0) {
                $lines[] = $this->line('employer', $code, $label, $amount, $lineBase, $rate);
            }
        }

        $employerCharges = $this->round($employerPension + $employerFamily + $employerRisk + $cfcEmployer + $fne);

        return new PayrollResult(
            baseSalary: $base,
            taxableAllowances: $in->taxableAllowances(),
            exemptAllowances: $in->nonTaxableAllowances(),
            overtime: $overtime,
            gross: $gross,
            taxableGross: $taxableGross,
            cnpsBase: $cnpsBase,
            cnpsBaseUncapped: $cnpsUncapped,
            cnpsEmployee: $cnpsEmployee,
            irpp: $irpp,
            cac: $cac,
            cfcEmployee: $cfcEmployee,
            tdl: $tdl,
            rav: $rav,
            otherDeductions: $other,
            advances: $advances,
            totalDeductions: $totalDeductions,
            netPay: $net,
            cnpsEmployerPension: $employerPension,
            cnpsEmployerFamily: $employerFamily,
            cnpsEmployerRisk: $employerRisk,
            cfcEmployer: $cfcEmployer,
            fne: $fne,
            employerCharges: $employerCharges,
            totalCost: $this->round($gross + $employerCharges),
            lines: $lines,
        );
    }

    /**
     * The month's IRPP.
     *
     * Annual taxable salary, less 30% frais professionnels, less the year's
     * CNPS pension contributions, less the 500 000 F of article 29 — that is
     * the revenu net catégoriel the scale is applied to. A twelfth of the
     * result is withheld.
     *
     * The 62 000 F threshold everybody quotes is not coded anywhere: it falls
     * out of this arithmetic, which is a good sign the arithmetic is right.
     */
    public function irppFor(float $monthlyTaxableGross, float $monthlyPension): float
    {
        $annualGross = $monthlyTaxableGross * 12;

        $expenses = $annualGross * (float) $this->get('irpp.professional_expenses.rate', 0.30);

        if (($cap = $this->get('irpp.professional_expenses.cap')) !== null) {
            $expenses = min($expenses, (float) $cap);
        }

        $pension = $this->get('irpp.deduct_pension', true) ? $monthlyPension * 12 : 0;

        $taxable = $annualGross - $expenses - $pension - (float) $this->get('irpp.annual_allowance', 500000);

        if ($taxable <= 0) {
            return 0.0;
        }

        return $this->round($this->applyBands($taxable) / 12);
    }

    /** The progressive scale, band by band on the slice that falls in each. */
    protected function applyBands(float $taxable): float
    {
        $tax = 0.0;
        $floor = 0.0;

        foreach ((array) $this->get('irpp.bands', []) as $band) {
            $ceiling = $band['upto'] === null ? INF : (float) $band['upto'];
            $slice = min($taxable, $ceiling) - $floor;

            if ($slice <= 0) {
                break;
            }

            $tax += $slice * (float) $band['rate'];
            $floor = $ceiling;

            if ($taxable <= $ceiling) {
                break;
            }
        }

        return $tax;
    }

    /**
     * A fixed amount read off a scale — the TDL and the RAV.
     *
     * Which figure the scale is read against differs between the two, and it
     * is not a detail:
     *
     *   TDL   the salaire de base. Its published barème is captioned as a
     *         retenue sur le salaire de base, so an allowance never moves the
     *         band.
     *   RAV   the gross taxable salary. Ordonnance 89/004 defines the base as
     *         "le montant brut des sommes retenues pour le calcul de l'impôt
     *         proportionnel sur les salaires" — the same figure the IRPP is
     *         computed on, which a taxable allowance does move.
     *
     * Reading both the same way would misstate one of them on every payslip
     * that carries an allowance.
     */
    protected function banded(string $key, float $base, float $gross, float $taxableGross): float
    {
        $against = match ($this->get("{$key}.basis", 'base')) {
            'gross' => $gross,
            'taxable_gross' => $taxableGross,
            default => $base,
        };

        $floor = (float) $this->get("{$key}.floor", 0);

        if ($against < $floor) {
            return 0.0;
        }

        foreach ((array) $this->get("{$key}.bands", []) as $band) {
            if ($band['upto'] === null || $against <= (float) $band['upto']) {
                return $this->round((float) $band['amount']);
            }
        }

        return 0.0;
    }

    /**
     * @return array{kind: string, code: string, label: string, base: ?float, rate: ?float, amount: float}
     */
    protected function line(string $kind, string $code, string $label, float $amount, ?float $base = null, ?float $rate = null): array
    {
        return compact('kind', 'code', 'label', 'base', 'rate', 'amount');
    }

    /**
     * Franc-level rounding.
     *
     * The CFA franc has no subunit; a payslip carrying centimes is a payslip
     * nobody can hand over a counter. `rounding` is a config key so an OHADA
     * country with a decimal currency can set it to 2.
     */
    protected function round(float $value): float
    {
        return round($value, (int) $this->get('rounding', 0));
    }

    /** Dot-path read out of the rates array. */
    protected function get(string $path, mixed $default = null): mixed
    {
        $value = $this->rates;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
