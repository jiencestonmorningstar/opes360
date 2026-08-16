<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What a supplier says our account with them looks like.
         *
         * Stored, unlike our own statement of their account, which is computed
         * from bills we already hold. This is the other side's assertion — it
         * arrives as a PDF or a spreadsheet and there is nowhere else to derive
         * it from — and it is the document a dispute is argued over months
         * later, so the version received has to survive unedited.
         */
        Schema::create('supplier_statements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('supplier_id')->constrained('contacts')->cascadeOnDelete();

            $table->string('reference')->nullable();
            $table->date('statement_date');
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();

            // The balance the supplier claims. Never recomputed from the lines:
            // when a supplier's own total disagrees with their own lines that
            // is a finding, and overwriting it would erase the finding.
            $table->decimal('closing_balance', 14, 2)->default(0);

            $table->string('currency', 3)->default('XAF');

            // open | reconciled | disputed
            $table->string('status', 20)->default('open');

            $table->text('notes')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'statement_date'], 'sup_stmt_company_date_idx');
            $table->index(['supplier_id', 'statement_date'], 'sup_stmt_supplier_date_idx');
        });

        Schema::create('supplier_statement_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('supplier_statement_id')->constrained('supplier_statements')->cascadeOnDelete();

            $table->date('line_date');
            $table->string('reference')->nullable();
            $table->string('description')->nullable();

            // Signed from the supplier's point of view: positive is a charge
            // they say we owe, negative is a payment or credit they say they
            // gave us. Folding a debit/credit pair into one signed figure at
            // import means every comparison downstream is one subtraction.
            $table->decimal('amount', 14, 2);

            // unmatched | matched | disputed | ignored
            $table->string('status', 20)->default('unmatched');

            // What our books say this line is. Nullable, because the whole
            // point of the exercise is the lines where the answer is nothing.
            $table->foreignUlid('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            // Named explicitly: the generated names for a table with this long
            // a prefix run past MySQL's 64-character identifier limit, which
            // SQLite accepts silently and MySQL refuses at deploy.
            $table->index(['supplier_statement_id', 'status'], 'sup_stmt_line_stmt_status_idx');
            $table->index(['company_id', 'expense_id'], 'sup_stmt_line_company_exp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_statement_lines');
        Schema::dropIfExists('supplier_statements');
    }
};
