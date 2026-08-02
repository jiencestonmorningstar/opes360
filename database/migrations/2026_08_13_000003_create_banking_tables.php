<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reconciling the books against the bank.
     *
     * ── The question this answers ────────────────────────────────────────
     *
     * "The books say we have 4 200 000 and the bank says 3 850 000 — which is
     * right?" Usually both: a cheque has not cleared, a bank charge has not
     * been recorded, a customer's transfer arrived and nobody noticed. The
     * only way to find out is to lay the two side by side and match them line
     * by line, which is what this is for.
     *
     * ── Matched, not merged ──────────────────────────────────────────────
     *
     * A statement line is what the BANK says happened. A journal line is what
     * the BUSINESS says happened. Matching them records that the two describe
     * the same event; it never rewrites either. That distinction is the whole
     * value of a reconciliation — the moment the software starts "correcting"
     * the books to agree with the statement, it can no longer tell you they
     * disagreed.
     *
     * A statement line with nothing to match is the useful case: it is either
     * something the business has not recorded (a bank charge, a standing
     * order) or something that should not have happened. The screen lets you
     * turn the first kind into a journal entry, which is then matched to it.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');                       // "UBA — compte courant"
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();  // RIB / IBAN
            $table->string('swift')->nullable();
            $table->string('currency', 3)->default('XAF');

            /*
             * The ledger account this mirrors. Two accounts at two banks need
             * two 52x accounts, or a reconciliation of one is a reconciliation
             * of neither — which is why this is required rather than assumed
             * to be the `bank` role.
             */
            $table->foreignUlid('ledger_account_id')->nullable()
                ->constrained('ledger_accounts')->nullOnDelete();

            // What the bank said, the last time anyone looked.
            $table->decimal('statement_balance', 16, 2)->default(0);
            $table->date('statement_date')->nullable();

            /*
             * Where the reconciliation starts.
             *
             * Without this a business three years into trading could never
             * reconcile: every movement it had ever posted would count as "in
             * the books but not on the statement", and the arithmetic would
             * insist the bank owed it three years of history. `opened_on` is
             * the line drawn under everything already settled, and
             * `opening_balance` is what both sides agreed on that day.
             */
            $table->decimal('opening_balance', 16, 2)->default(0);
            $table->date('opened_on')->nullable();

            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'active']);
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();

            $table->date('value_date');
            $table->string('description');
            $table->string('reference')->nullable();

            /*
             * Signed, from the account holder's point of view: positive is
             * money in. Storing one signed column rather than a debit and a
             * credit pair, because a bank statement has one amount per line
             * and splitting it invites a row that is somehow both.
             */
            $table->decimal('amount', 16, 2);
            $table->decimal('running_balance', 16, 2)->nullable();

            $table->string('status')->default('unmatched'); // unmatched|matched|ignored

            // What it was matched to, when it was. Nullable morph, because a
            // line can equally match a payment, an expense payment or a raw
            // journal entry.
            $table->nullableUlidMorphs('matched_to');
            $table->foreignUlid('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('matched_at')->nullable();
            $table->foreignId('matched_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('import_batch')->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'bank_account_id', 'status']);
            $table->index(['company_id', 'value_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_accounts');
    }
};
