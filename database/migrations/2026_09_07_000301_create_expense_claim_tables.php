<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money a member of staff spent out of their own pocket. ERP checklist #8.
     *
     * Deliberately not another row in `expenses`. An expense is money the
     * business paid; a claim is money the business owes a person until it
     * reimburses them, and that debt sits in 422 rather than 401 because the
     * creditor is an employee, not a supplier. Folding the two together would
     * mean the payables ageing report started listing staff.
     */
    public function up(): void
    {
        Schema::create('expense_claims', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->string('number')->nullable();
            $table->string('title');
            $table->date('claim_date');

            // draft|submitted|approved|rejected|reimbursed. Whether the
            // approval itself passed is the workflow engine's answer, never
            // this column's — it is a cache of the outcome for listing and
            // filtering, and the engine stays the authority.
            $table->string('status')->default('draft');

            $table->string('currency', 3)->default('XAF');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('amount_reimbursed', 14, 2)->default(0);

            $table->text('notes')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('reimbursed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'employee_id'], 'expense_claims_company_employee_idx');
        });

        Schema::create('expense_claim_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('expense_claim_id')->constrained('expense_claims')->cascadeOnDelete();

            /*
             * Which budget this line comes out of. Held per line rather than
             * per claim: one trip routinely spends against two budgets, and a
             * claim-level allocation would force staff to file two claims for
             * one journey, or to allocate the whole lot to whichever centre
             * happened to be the larger share.
             */
            $table->foreignUlid('cost_centre_id')->nullable()
                ->constrained('cost_centres')->nullOnDelete();

            // Resolved at entry time, like Expense: recategorising the list
            // later must not silently rewrite what a past month was charged to.
            $table->foreignUlid('ledger_account_id')->nullable()
                ->constrained('ledger_accounts')->nullOnDelete();

            $table->string('description');
            $table->string('category');
            $table->string('reference')->nullable();   // the receipt number
            $table->date('incurred_on');

            $table->decimal('amount', 14, 2);          // hors taxes
            $table->decimal('vat_rate', 5, 4)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Named: the generated name for these three would run past MySQL's
            // 64-character identifier limit, which SQLite accepts silently.
            $table->index(['company_id', 'cost_centre_id'], 'claim_lines_company_centre_idx');
            $table->index(['expense_claim_id', 'sort_order'], 'claim_lines_claim_order_idx');
        });

        Schema::create('expense_claim_reimbursements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('expense_claim_id')->constrained('expense_claims')->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('XAF');
            $table->string('method');                  // cash|mobile_money|bank|cheque
            $table->string('reference')->nullable();
            $table->date('paid_on');
            $table->text('note')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'paid_on'], 'claim_reimb_company_paid_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_claim_reimbursements');
        Schema::dropIfExists('expense_claim_lines');
        Schema::dropIfExists('expense_claims');
    }
};
