<?php

namespace Tests\Unit;

use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollInput;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic, on its own.
 *
 * No database, no company, no Laravel — the calculator is handed a rates array
 * and a month and hands back figures, which is exactly what makes these
 * assertions worth having. Every expected number below was worked out by hand
 * from the published rules and is written out in the comment above it, so a
 * failure says which step disagrees rather than only that something does.
 */
class PayrollCalculatorTest extends TestCase
{
    /** The real rates, loaded straight from the config file. */
    protected function calculator(array $overrides = []): PayrollCalculator
    {
        $rates = require __DIR__.'/../../config/payroll.php';

        return new PayrollCalculator(array_replace_recursive($rates, $overrides));
    }

    // ──────────────────────────────────────────────────────── the bases ──

    public function test_a_flat_salary_is_its_own_gross_and_taxable_base(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(baseSalary: 200000));

        $this->assertSame(200000.0, $r->gross);
        $this->assertSame(200000.0, $r->taxableGross);
        $this->assertSame(200000.0, $r->cnpsBase);
    }

    /**
     * The three bases come apart the moment an allowance is exempt from one
     * thing and not the other. This is the case a hand-built spreadsheet gets
     * wrong.
     */
    public function test_an_allowance_can_sit_in_one_base_and_not_the_other(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(
            baseSalary: 200000,
            allowances: [
                ['label' => 'Transport', 'amount' => 25000, 'taxable' => false, 'cnps' => false],
                ['label' => 'Prime de rendement', 'amount' => 30000, 'taxable' => true, 'cnps' => true],
                ['label' => 'Logement', 'amount' => 40000, 'taxable' => true, 'cnps' => false],
            ],
        ));

        $this->assertSame(295000.0, $r->gross, 'Everything earned.');
        $this->assertSame(270000.0, $r->taxableGross, 'Everything but the transport allowance.');
        $this->assertSame(230000.0, $r->cnpsBaseUncapped, 'Base plus the one allowance CNPS counts.');
        $this->assertSame(25000.0, $r->exemptAllowances);
    }

    // ───────────────────────────────────────────────────────────── CNPS ──

    /** 200 000 × 4.2% = 8 400. */
    public function test_the_employee_pays_4_2_percent_pension(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(baseSalary: 200000));

        $this->assertSame(8400.0, $r->cnpsEmployee);
    }

    /**
     * The ceiling is 750 000. Above it the contribution stops moving, which is
     * the whole point of a ceiling and the thing most spreadsheets forget.
     */
    public function test_the_pension_contribution_stops_at_the_ceiling(): void
    {
        $at = $this->calculator()->compute(new PayrollInput(baseSalary: 750000));
        $above = $this->calculator()->compute(new PayrollInput(baseSalary: 1200000));

        $this->assertSame(31500.0, $at->cnpsEmployee, '750 000 × 4.2%.');
        $this->assertSame(31500.0, $above->cnpsEmployee, 'Unchanged above the ceiling.');
        $this->assertSame(750000.0, $above->cnpsBase);
        $this->assertSame(1200000.0, $above->cnpsBaseUncapped);
    }

    /**
     * Occupational risk is the one CNPS branch computed on the whole salary.
     * 1 200 000 × 1.75% = 21 000, not 750 000 × 1.75% = 13 125.
     */
    public function test_occupational_risk_is_not_capped(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(baseSalary: 1200000));

        $this->assertSame(21000.0, $r->cnpsEmployerRisk);
        $this->assertSame(31500.0, $r->cnpsEmployerPension, 'Pension is capped…');
        $this->assertSame(52500.0, $r->cnpsEmployerFamily, '…and so are family allowances.');
    }

    public function test_the_risk_rate_follows_the_business_group(): void
    {
        $low = $this->calculator()->compute(new PayrollInput(baseSalary: 400000, riskGroup: 'a'));
        $high = $this->calculator()->compute(new PayrollInput(baseSalary: 400000, riskGroup: 'c'));

        $this->assertSame(7000.0, $low->cnpsEmployerRisk, '400 000 × 1.75%.');
        $this->assertSame(20000.0, $high->cnpsEmployerRisk, '400 000 × 5%.');
    }

    // ───────────────────────────────────────────────────────────── IRPP ──

    /**
     * Worked by hand for a 300 000 F salary:
     *
     *   annual gross           3 600 000
     *   less 30% frais pro    −1 080 000
     *   less CNPS 12 × 12 600  −151 200
     *   less article 29        −500 000
     *   = revenu net catégoriel 1 868 800, all inside the 10% band
     *   → 186 880 a year → 15 573 a month (rounded to the franc)
     */
    public function test_the_irpp_matches_the_worked_example(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(baseSalary: 300000));

        $this->assertSame(12600.0, $r->cnpsEmployee);
        $this->assertSame(15573.0, $r->irpp);
        $this->assertSame(1557.0, $r->cac, '10% of the tax.');
    }

    /**
     * The 62 000 F floor everyone quotes is not coded anywhere — it falls out
     * of the abatements. If this test ever fails, one of them has been changed.
     */
    public function test_a_low_salary_owes_no_income_tax(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(baseSalary: 62000));

        $this->assertSame(0.0, $r->irpp);
        $this->assertSame(0.0, $r->cac);
    }

    /**
     * A high salary crosses three bands, so the tax is not a single rate on
     * the whole base.
     *
     *   annual gross          14 400 000  (1 200 000 a month)
     *   less 30%              −4 320 000
     *   less CNPS 12 × 31 500  −378 000   (capped contribution)
     *   less article 29         −500 000
     *   = 9 202 000
     *   10% of 2 000 000     =   200 000
     *   15% of 1 000 000     =   150 000
     *   25% of 2 000 000     =   500 000
     *   35% of 4 202 000     = 1 470 700
     *   → 2 320 700 a year → 193 392 a month
     */
    public function test_the_bands_are_applied_slice_by_slice(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(baseSalary: 1200000));

        $this->assertSame(193392.0, $r->irpp);
    }

    /** An exempt allowance must not raise the tax. */
    public function test_an_exempt_allowance_stays_out_of_the_tax(): void
    {
        $plain = $this->calculator()->compute(new PayrollInput(baseSalary: 300000));

        $withExempt = $this->calculator()->compute(new PayrollInput(
            baseSalary: 300000,
            allowances: [['label' => 'Transport', 'amount' => 25000, 'taxable' => false, 'cnps' => false]],
        ));

        $this->assertSame($plain->irpp, $withExempt->irpp);
        $this->assertSame($plain->cfcEmployee, $withExempt->cfcEmployee);
        // But the employee is 25 000 better off.
        $this->assertSame(round($plain->netPay + 25000, 2), $withExempt->netPay);
    }

    // ─────────────────────────────────────────────────── banded charges ──

    public function test_the_tdl_is_read_off_the_scale_and_capped(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(0.0, $calculator->compute(new PayrollInput(baseSalary: 50000))->tdl, 'Below the floor.');
        $this->assertSame(250.0, $calculator->compute(new PayrollInput(baseSalary: 62000))->tdl, 'First band.');
        $this->assertSame(1000.0, $calculator->compute(new PayrollInput(baseSalary: 150000))->tdl);
        $this->assertSame(2500.0, $calculator->compute(new PayrollInput(baseSalary: 900000))->tdl, 'Flat at the top.');
        $this->assertSame(2500.0, $calculator->compute(new PayrollInput(baseSalary: 9000000))->tdl);
    }

    public function test_the_rav_is_read_off_its_own_scale(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(0.0, $calculator->compute(new PayrollInput(baseSalary: 45000))->rav);
        $this->assertSame(750.0, $calculator->compute(new PayrollInput(baseSalary: 60000))->rav);
        $this->assertSame(3250.0, $calculator->compute(new PayrollInput(baseSalary: 250000))->rav);
        $this->assertSame(13000.0, $calculator->compute(new PayrollInput(baseSalary: 2000000))->rav);
    }

    /**
     * The band is read against the base salary, so an allowance cannot push
     * someone into a higher one.
     */
    public function test_an_allowance_does_not_move_the_tdl_band(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(
            baseSalary: 145000,
            allowances: [['label' => 'Prime', 'amount' => 60000]],
        ));

        $this->assertSame(1000.0, $r->tdl, 'The 125 001–150 000 band, on the base alone.');
    }

    /**
     * The whole point of the switches: a business whose accountant has not
     * confirmed the RAV scale can stop withholding it rather than take money
     * it cannot justify.
     */
    public function test_a_business_can_switch_off_the_banded_withholdings(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(
            baseSalary: 300000,
            withholdTdl: false,
            withholdRav: false,
        ));

        $this->assertSame(0.0, $r->tdl);
        $this->assertSame(0.0, $r->rav);
    }

    // ──────────────────────────────────────────────────────── the whole ──

    /**
     * Everything at once, for a 300 000 F salary:
     *
     *   CNPS 12 600 · IRPP 15 573 · CAC 1 557 · CFC 3 000 · TDL 2 000 · RAV 3 250
     *   = 37 980 withheld, so 262 020 in hand.
     */
    public function test_the_net_is_the_gross_less_everything_withheld(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(baseSalary: 300000));

        $this->assertSame(37980.0, $r->totalDeductions);
        $this->assertSame(262020.0, $r->netPay);
        $this->assertSame(round($r->gross - $r->totalDeductions, 2), $r->netPay);
    }

    /**
     * The employer's own bill on the same salary:
     *
     *   pension 12 600 · famille 21 000 · risque 5 250 · CFC 4 500 · FNE 3 000
     *   = 46 350, so the person costs 346 350 rather than the 300 000 agreed.
     */
    public function test_the_employer_charges_are_a_fifth_again_on_top(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(baseSalary: 300000));

        $this->assertSame(12600.0, $r->cnpsEmployerPension);
        $this->assertSame(21000.0, $r->cnpsEmployerFamily);
        $this->assertSame(5250.0, $r->cnpsEmployerRisk);
        $this->assertSame(4500.0, $r->cfcEmployer);
        $this->assertSame(3000.0, $r->fne);
        $this->assertSame(46350.0, $r->employerCharges);
        $this->assertSame(346350.0, $r->totalCost);
    }

    public function test_a_deduction_comes_off_the_net_without_touching_any_base(): void
    {
        $plain = $this->calculator()->compute(new PayrollInput(baseSalary: 300000));

        $withLoan = $this->calculator()->compute(new PayrollInput(
            baseSalary: 300000,
            deductions: [['label' => 'Remboursement prêt', 'amount' => 20000]],
        ));

        $this->assertSame($plain->irpp, $withLoan->irpp, 'A loan repayment is not a tax relief.');
        $this->assertSame($plain->cnpsEmployee, $withLoan->cnpsEmployee);
        $this->assertSame(20000.0, $withLoan->otherDeductions);
        $this->assertSame(round($plain->netPay - 20000, 2), $withLoan->netPay);
    }

    /** Every figure on the payslip has to be explainable line by line. */
    public function test_every_charge_produces_a_line_that_names_its_base_and_rate(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(
            baseSalary: 300000,
            allowances: [['label' => 'Prime de transport', 'amount' => 25000, 'taxable' => false, 'cnps' => false]],
        ));

        $codes = array_column($r->lines, 'code');

        foreach (['base', 'cnps', 'irpp', 'cac', 'cfc', 'tdl', 'rav', 'cnps_pension_er', 'cnps_family', 'cnps_risk', 'cfc_er', 'fne'] as $code) {
            $this->assertContains($code, $codes, "The payslip has no [{$code}] line to explain that charge.");
        }

        $allowance = collect($r->lines)->firstWhere('label', 'Prime de transport');
        $this->assertNotNull($allowance, 'An allowance appears under its own name, not lumped into a total.');
        $this->assertSame(25000.0, $allowance['amount']);

        $cnps = collect($r->lines)->firstWhere('code', 'cnps');
        $this->assertSame(300000.0, $cnps['base']);
        $this->assertSame(0.042, $cnps['rate']);

        // The three sides add up to what the columns say.
        $sum = fn (string $kind) => round(array_sum(array_column(
            array_filter($r->lines, fn ($l) => $l['kind'] === $kind), 'amount'
        )), 2);

        $this->assertSame($r->gross, $sum('earning'));
        $this->assertSame($r->totalDeductions, $sum('deduction'));
        $this->assertSame($r->employerCharges, $sum('employer'));
    }

    /** The CFA franc has no subunit, so nothing may carry centimes. */
    public function test_every_figure_is_a_whole_franc(): void
    {
        $r = $this->calculator()->compute(new PayrollInput(
            baseSalary: 137777,
            allowances: [['label' => 'Prime', 'amount' => 13333]],
        ));

        foreach ($r->toColumns() as $key => $value) {
            $this->assertSame((float) round($value), (float) $value, "[{$key}] carries centimes.");
        }
    }

    /**
     * The rates are a parameter, not a constant. A payroll run from before a
     * finance act must compute with the rates it recorded — this is the
     * mechanism that makes that possible.
     */
    public function test_the_calculator_holds_no_rates_of_its_own(): void
    {
        $doubled = $this->calculator(['cnps' => ['pension' => ['employee' => 0.084]]]);

        $this->assertSame(16800.0, $doubled->compute(new PayrollInput(baseSalary: 200000))->cnpsEmployee);
        // And the real config is untouched by that.
        $this->assertSame(8400.0, $this->calculator()->compute(new PayrollInput(baseSalary: 200000))->cnpsEmployee);
    }
}
