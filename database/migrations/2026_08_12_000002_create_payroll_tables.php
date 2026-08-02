<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Payroll runs and the payslips they produce.
     *
     * ── Why a payslip stores its figures instead of computing them ───────
     *
     * Because the rates change. When the finance act moves the IRPP bands in
     * January, every payslip already issued must keep saying what it said —
     * the employee has a copy, the CNPS has a declaration built from it, and
     * "recompute on read" would quietly rewrite last year's payroll the day
     * config/payroll.php is edited. So a payslip is a record of an arithmetic
     * that happened, not a view over one that could happen. The lines are
     * stored too, for the same reason and because a payslip has to be able to
     * explain itself line by line to the person it belongs to.
     *
     * ── Why the employer's charges live on the payslip ──────────────────
     *
     * They are not withheld from anyone and never appear on the copy handed
     * to the employee, but they are computed from the same base at the same
     * moment, and the business needs them per person to know what a member of
     * staff actually costs. Keeping them anywhere else means computing the
     * same base twice and having the two disagree.
     */
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Always the first of the month it pays for. One run per period,
            // which the unique index below enforces.
            $table->date('period');
            $table->string('label')->nullable();
            $table->date('pay_date')->nullable();

            $table->string('status')->default('draft'); // draft|approved|paid|void

            // Totals across the run, so the list does not aggregate payslips
            // to show a figure.
            $table->decimal('gross', 16, 2)->default(0);
            $table->decimal('employee_deductions', 16, 2)->default(0);
            $table->decimal('net', 16, 2)->default(0);
            $table->decimal('employer_charges', 16, 2)->default(0);
            $table->unsignedInteger('headcount')->default(0);

            $table->string('currency', 3)->default('XAF');

            /*
             * The rates this run was computed with, copied in at approval.
             * config/payroll.php will change; this is how a payslip from two
             * years ago can still be explained.
             */
            $table->json('rates')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'period']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignUlid('employment_contract_id')->nullable()
                ->constrained('employment_contracts')->nullOnDelete();

            $table->string('number')->nullable();

            // ── Earnings ─────────────────────────────────────────────────
            $table->decimal('base_salary', 14, 2)->default(0);
            $table->decimal('taxable_allowances', 14, 2)->default(0);
            $table->decimal('exempt_allowances', 14, 2)->default(0);
            $table->decimal('overtime', 14, 2)->default(0);
            $table->decimal('gross', 14, 2)->default(0);

            // The three different bases the law computes against, kept apart
            // because they genuinely differ: CNPS is capped, tax is not, and
            // an exempt allowance is in neither.
            $table->decimal('taxable_gross', 14, 2)->default(0);
            $table->decimal('cnps_base', 14, 2)->default(0);   // capped at the ceiling
            $table->decimal('cnps_base_uncapped', 14, 2)->default(0);

            // ── Withheld from the employee ───────────────────────────────
            $table->decimal('cnps_employee', 14, 2)->default(0);
            $table->decimal('irpp', 14, 2)->default(0);
            $table->decimal('cac', 14, 2)->default(0);
            $table->decimal('cfc_employee', 14, 2)->default(0);
            $table->decimal('tdl', 14, 2)->default(0);
            $table->decimal('rav', 14, 2)->default(0);
            $table->decimal('other_deductions', 14, 2)->default(0);
            $table->decimal('advances', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);

            $table->decimal('net_pay', 14, 2)->default(0);

            // ── Borne by the employer, never withheld ────────────────────
            $table->decimal('cnps_employer_pension', 14, 2)->default(0);
            $table->decimal('cnps_employer_family', 14, 2)->default(0);
            $table->decimal('cnps_employer_risk', 14, 2)->default(0);
            $table->decimal('cfc_employer', 14, 2)->default(0);
            $table->decimal('fne', 14, 2)->default(0);
            $table->decimal('employer_charges', 14, 2)->default(0);

            // What the person costs, all in. The number a business owner
            // actually wants and almost never has.
            $table->decimal('total_cost', 14, 2)->default(0);

            $table->decimal('days_worked', 5, 2)->nullable();
            $table->decimal('days_absent', 5, 2)->default(0);
            $table->decimal('leave_days', 5, 2)->default(0);

            $table->string('currency', 3)->default('XAF');
            $table->string('status')->default('draft'); // draft|approved|paid
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();
            $table->date('paid_on')->nullable();

            // Who this was, at the time. A payslip has to keep naming the
            // person and the job it was issued for even after a rename.
            $table->json('snapshot')->nullable();

            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
            $table->index(['company_id', 'employee_id']);
            $table->index(['company_id', 'status']);
        });

        /*
         * The itemised payslip. Not derived from the columns above at render
         * time: a payslip has to name a "Prime de transport" of 25 000 F that
         * no column knows about, and an employee reading it is entitled to see
         * every figure that made up the total.
         */
        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('payslip_id')->constrained('payslips')->cascadeOnDelete();

            $table->string('kind');   // earning|deduction|employer
            $table->string('code')->nullable();
            $table->string('label');

            $table->decimal('base', 14, 2)->nullable();
            $table->decimal('rate', 8, 5)->nullable();
            $table->decimal('amount', 14, 2)->default(0);

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'payslip_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
    }
};
