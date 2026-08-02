<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money going out.
     *
     * Until now the books only knew about revenue: documents, receipts and
     * payments in. A profit-and-loss statement built from half the story is
     * worse than none, because it looks complete.
     *
     * One table covers both shapes a small business actually deals with,
     * because the difference between them is a due date rather than a kind of
     * thing:
     *
     *   a supplier bill — has a supplier, a reference and terms, and sits in
     *   payables (401) until it is settled;
     *   a direct expense — fuel, airtime, a taxi — paid on the spot, straight
     *   from cash or bank with nothing left owing.
     *
     * Splitting them into two tables would mean two lists, two reports and two
     * places to look for "what did we spend in March".
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Null for a direct expense with no supplier worth recording.
            $table->foreignUlid('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('number')->nullable();      // our own sequence
            $table->string('reference')->nullable();   // the supplier's invoice number
            $table->string('description');
            $table->string('category');

            /*
             * The SYSCOHADA account this is charged to. Held as an id rather
             * than derived from the category at report time, so recategorising
             * the list later cannot silently rewrite what a past month was
             * posted against.
             */
            $table->foreignUlid('ledger_account_id')->nullable()
                ->constrained('ledger_accounts')->nullOnDelete();

            $table->date('issue_date');
            $table->date('due_date')->nullable();

            $table->decimal('amount', 14, 2);          // hors taxes
            $table->decimal('vat_rate', 5, 4)->default(0);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);           // toutes taxes comprises
            $table->decimal('amount_paid', 14, 2)->default(0);

            $table->string('currency', 3)->default('XAF');
            $table->string('status')->default('recorded'); // draft|recorded|void
            $table->string('payment_method')->nullable();  // cash|mobile_money|bank|cheque
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'issue_date']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'supplier_id']);
            $table->index(['company_id', 'category']);
        });

        /*
         * A payment against an expense. Separate from `payments`, which records
         * money coming in and is allocated against receivables — the two share
         * a word and nothing else, and merging them would mean every query
         * about cash received had to remember to exclude cash paid.
         */
        Schema::create('expense_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('expense_id')->constrained('expenses')->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('XAF');
            $table->string('method');                 // cash|mobile_money|bank|cheque
            $table->string('reference')->nullable();
            $table->date('paid_on');
            $table->text('note')->nullable();

            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'paid_on']);
            $table->index(['company_id', 'expense_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_payments');
        Schema::dropIfExists('expenses');
    }
};
