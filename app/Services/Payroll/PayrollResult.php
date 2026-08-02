<?php

namespace App\Services\Payroll;

/**
 * One computed payslip, before anything is written down.
 *
 * Every figure the payslip stores, plus the lines that explain them. Nothing
 * here is derived on read: if the object says the IRPP was 18 400 F, that is
 * the number that was computed at the time, not one this class would arrive at
 * again today.
 */
class PayrollResult
{
    /**
     * @param  array<int, array{kind: string, code: string, label: string, base: ?float, rate: ?float, amount: float}>  $lines
     */
    public function __construct(
        public readonly float $baseSalary,
        public readonly float $taxableAllowances,
        public readonly float $exemptAllowances,
        public readonly float $overtime,
        public readonly float $gross,
        public readonly float $taxableGross,
        public readonly float $cnpsBase,
        public readonly float $cnpsBaseUncapped,
        public readonly float $cnpsEmployee,
        public readonly float $irpp,
        public readonly float $cac,
        public readonly float $cfcEmployee,
        public readonly float $tdl,
        public readonly float $rav,
        public readonly float $otherDeductions,
        public readonly float $advances,
        public readonly float $totalDeductions,
        public readonly float $netPay,
        public readonly float $cnpsEmployerPension,
        public readonly float $cnpsEmployerFamily,
        public readonly float $cnpsEmployerRisk,
        public readonly float $cfcEmployer,
        public readonly float $fne,
        public readonly float $employerCharges,
        public readonly float $totalCost,
        public readonly array $lines = [],
    ) {}

    /** The payslip columns, ready to be written. */
    public function toColumns(): array
    {
        return [
            'base_salary' => $this->baseSalary,
            'taxable_allowances' => $this->taxableAllowances,
            'exempt_allowances' => $this->exemptAllowances,
            'overtime' => $this->overtime,
            'gross' => $this->gross,
            'taxable_gross' => $this->taxableGross,
            'cnps_base' => $this->cnpsBase,
            'cnps_base_uncapped' => $this->cnpsBaseUncapped,
            'cnps_employee' => $this->cnpsEmployee,
            'irpp' => $this->irpp,
            'cac' => $this->cac,
            'cfc_employee' => $this->cfcEmployee,
            'tdl' => $this->tdl,
            'rav' => $this->rav,
            'other_deductions' => $this->otherDeductions,
            'advances' => $this->advances,
            'total_deductions' => $this->totalDeductions,
            'net_pay' => $this->netPay,
            'cnps_employer_pension' => $this->cnpsEmployerPension,
            'cnps_employer_family' => $this->cnpsEmployerFamily,
            'cnps_employer_risk' => $this->cnpsEmployerRisk,
            'cfc_employer' => $this->cfcEmployer,
            'fne' => $this->fne,
            'employer_charges' => $this->employerCharges,
            'total_cost' => $this->totalCost,
        ];
    }

    /** Everything withheld and remitted to the state, on either side. */
    public function statutoryWithheld(): float
    {
        return round($this->cnpsEmployee + $this->irpp + $this->cac + $this->cfcEmployee + $this->tdl + $this->rav, 2);
    }
}
