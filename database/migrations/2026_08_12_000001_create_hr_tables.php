<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * People, and what they are owed.
     *
     * ── An employee is not a user ────────────────────────────────────────
     *
     * The single most important decision in this schema. A small business in
     * Douala has a driver, two shop assistants and a night watchman; none of
     * them will ever log in, and several have no email address. Modelling an
     * employee as a `users` row would mean inventing credentials for people
     * who do not want them, and would tie the payroll to the seat count.
     *
     * So `employees` is its own table, and `user_id` is a nullable link for
     * the ones who do also have an account. Deleting a login does not delete a
     * person's employment history, which is the other half of why.
     *
     * ── Contracts are separate from employees ───────────────────────────
     *
     * Because pay changes. An employee promoted in June must produce June's
     * payslip at June's salary for the rest of time, so a payroll run reads
     * the contract that was in force on its own date rather than whatever the
     * employee record says today. One employee, many contracts, at most one
     * active at a time.
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Optional. Most staff never log in; the ones who do are linked here.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('number')->nullable();   // staff number, per company
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender')->nullable();   // female|male|unspecified
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('nationality')->nullable();
            $table->string('marital_status')->nullable();
            $table->unsignedTinyInteger('dependants')->default(0);

            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();

            // The three numbers a Cameroonian payslip and declaration need.
            $table->string('national_id')->nullable();  // CNI
            $table->string('cnps_number')->nullable();
            $table->string('niu')->nullable();          // taxpayer number

            $table->string('job_title')->nullable();
            $table->string('department')->nullable();

            $table->date('hired_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->string('end_reason')->nullable();
            $table->string('status')->default('active'); // active|suspended|ended

            // How this person is actually paid. Cash is the honest default in a
            // market where a majority of staff have no bank account.
            $table->string('payment_method')->default('cash'); // cash|mobile_money|bank|cheque
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->string('mobile_money_number')->nullable();

            $table->string('emergency_contact')->nullable();
            $table->string('emergency_phone')->nullable();

            // Days carried into the current leave year, plus what has accrued.
            $table->decimal('leave_opening_balance', 6, 2)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'last_name']);
            $table->unique(['company_id', 'number']);
        });

        Schema::create('employment_contracts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('type')->default('cdi');  // cdi|cdd|stage|essai|prestation
            $table->string('reference')->nullable();
            $table->string('job_title')->nullable();

            $table->date('starts_on');
            $table->date('ends_on')->nullable();     // null for a CDI
            $table->date('trial_ends_on')->nullable();
            $table->date('signed_on')->nullable();

            // Monthly, gross, before allowances. The figure the TDL and RAV
            // bands are read against.
            $table->decimal('base_salary', 14, 2)->default(0);
            $table->string('currency', 3)->default('XAF');

            // Catégorie professionnelle and échelon of the convention
            // collective. Recorded rather than computed from: the grids differ
            // by sector, and getting them wrong is worse than not showing them.
            $table->string('category')->nullable();
            $table->string('echelon')->nullable();

            $table->decimal('hours_per_week', 5, 2)->default(40);

            $table->string('status')->default('active'); // draft|active|ended
            $table->date('ended_on')->nullable();
            $table->text('terms')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id', 'status']);
            $table->index(['company_id', 'starts_on']);
        });

        /*
         * Recurring allowances and deductions attached to a person: a transport
         * allowance, a housing allowance, a loan repayment.
         *
         * `taxable` and `cnps_liable` are the two flags that matter and they
         * are genuinely independent — a transport allowance within the legal
         * limit is exempt from both, a housing allowance is generally liable
         * to tax but treated differently for CNPS, and hard-coding either
         * would misstate a payslip. The business declares what each one is.
         */
        Schema::create('salary_components', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('name');
            $table->string('kind')->default('allowance'); // allowance|deduction

            // Either a flat amount or a percentage of the base salary.
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('rate', 6, 4)->nullable();

            $table->boolean('taxable')->default(true);
            $table->boolean('cnps_liable')->default(true);
            $table->boolean('active')->default(true);

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'active']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('type')->default('annual');
            $table->date('starts_on');
            $table->date('ends_on');

            // Stored rather than derived: which days count is a decision about
            // the business's working week, and recomputing it years later with
            // a different setting would silently rewrite history.
            $table->decimal('days', 6, 2)->default(0);

            $table->boolean('paid')->default(true);
            $table->boolean('deducts_balance')->default(true);

            $table->string('status')->default('pending'); // pending|approved|declined|cancelled
            $table->text('reason')->nullable();
            $table->text('decision_note')->nullable();

            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'employee_id', 'status']);
            $table->index(['company_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('salary_components');
        Schema::dropIfExists('employment_contracts');
        Schema::dropIfExists('employees');
    }
};
