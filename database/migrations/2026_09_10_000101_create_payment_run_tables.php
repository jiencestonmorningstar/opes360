<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A payment run: the decision about which bills get paid this time.
         *
         * The schedule itself is computed, not stored — like the aging report,
         * a stored one is wrong by the time anybody opens it. What is worth
         * keeping is the decision a human made from it, because that is the
         * thing somebody will be asked about later: why was this supplier paid
         * in full while that one waited another fortnight.
         */
        Schema::create('payment_runs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference')->nullable();

            // The day the money is meant to leave, which is not the day the run
            // was built. A run prepared on Friday for Monday's transfers must
            // be judged against Monday's cash, not Friday's.
            $table->date('scheduled_for');

            // draft | approved | executed | cancelled
            $table->string('status', 20)->default('draft');

            /*
             * The cash the run was built against, frozen at build time. Without
             * it, a run approved on Monday and questioned on Thursday cannot be
             * defended: the forecast has moved and nobody can reconstruct the
             * number the decision was actually made from.
             */
            $table->decimal('cash_available', 14, 2)->default(0);

            $table->string('currency', 3)->default('XAF');
            $table->text('notes')->nullable();

            // foreignId, not foreignUlid: users.id is a bigint. A ULID column
            // pointing at it compiles on SQLite and is refused by MySQL, so the
            // tests would pass and the deploy would not.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'scheduled_for'], 'pay_run_company_scheduled_idx');
            $table->index(['company_id', 'status'], 'pay_run_company_status_idx');
        });

        Schema::create('payment_run_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('payment_run_id')->constrained('payment_runs')->cascadeOnDelete();
            $table->foreignUlid('expense_id')->constrained('expenses')->cascadeOnDelete();

            // Part-payment is the normal case when cash is short, so this is the
            // amount decided for this run rather than the bill's balance.
            $table->decimal('amount', 14, 2);

            $table->string('method', 20)->nullable();  // cash|mobile_money|bank|cheque

            // pending | paid | skipped
            $table->string('status', 20)->default('pending');

            $table->text('note')->nullable();
            $table->foreignUlid('expense_payment_id')->nullable()
                ->constrained('expense_payments')->nullOnDelete();

            $table->timestamps();

            // One line per bill per run. Two lines against the same bill would
            // let a run pay it twice and still look balanced against the cash
            // it was budgeted from.
            $table->unique(['payment_run_id', 'expense_id'], 'pay_run_item_unique');
            $table->index(['company_id', 'status'], 'pay_run_item_company_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_run_items');
        Schema::dropIfExists('payment_runs');
    }
};
