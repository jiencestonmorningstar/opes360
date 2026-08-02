<?php

namespace App\Services\Payroll;

/**
 * Everything the calculator needs about one person for one month, and nothing
 * about where it came from.
 *
 * Allowances arrive as a list rather than as three pre-summed buckets because
 * the calculator has to emit the payslip's lines as well as its totals, and an
 * employee reading "Allowances 45 000" instead of "Transport 25 000, Housing
 * 20 000" has been told a number rather than shown one.
 */
class PayrollInput
{
    /**
     * @param  array<int, array{label: string, amount: float, taxable?: bool, cnps?: bool, code?: string}>  $allowances
     * @param  array<int, array{label: string, amount: float, code?: string}>  $deductions
     */
    public function __construct(
        public readonly float $baseSalary,
        public readonly array $allowances = [],
        public readonly array $deductions = [],
        public readonly float $overtime = 0,
        public readonly float $advances = 0,
        public readonly string $riskGroup = 'a',
        public readonly string $familyRegime = 'general',
        public readonly bool $withholdTdl = true,
        public readonly bool $withholdRav = true,
    ) {}

    /** Allowances inside the tax base. */
    public function taxableAllowances(): float
    {
        return $this->sum(fn (array $a) => ($a['taxable'] ?? true) === true);
    }

    /** Allowances inside the CNPS base. Independent of the tax question. */
    public function cnpsAllowances(): float
    {
        return $this->sum(fn (array $a) => ($a['cnps'] ?? true) === true);
    }

    /**
     * Allowances outside the tax base. This is the figure a payslip shows as
     * "exempt", which is a statement about tax; whether they are also outside
     * the CNPS base is the separate question `cnpsAllowances` answers.
     */
    public function nonTaxableAllowances(): float
    {
        return $this->sum(fn (array $a) => ($a['taxable'] ?? true) === false);
    }

    public function totalAllowances(): float
    {
        return $this->sum(fn () => true);
    }

    public function totalDeductions(): float
    {
        return round(array_sum(array_map(fn (array $d) => (float) $d['amount'], $this->deductions)), 2);
    }

    protected function sum(callable $filter): float
    {
        return round(array_sum(array_map(
            fn (array $a) => (float) $a['amount'],
            array_filter($this->allowances, $filter)
        )), 2);
    }
}
