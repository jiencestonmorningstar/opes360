<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The SYSCOHADA books: a chart of accounts per company, and a double-entry
     * journal over it.
     *
     * The chart is per company rather than global. OHADA fixes the classes and
     * the standard accounts, but every business subdivides them — one sales
     * account per branch, a bank account per bank — and an accountant who
     * cannot add 5211 for a second bank will keep their real books elsewhere.
     *
     * Entries are immutable once posted. An accounting system that lets you
     * edit last month's entry cannot answer "what did the books say in March",
     * which is the only question anyone asks them. A mistake is corrected the
     * way accountants correct mistakes: a reversing entry, which is why
     * `reverses_entry_id` exists and `deleted_at` does not.
     */
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            // SYSCOHADA numbers are strings, not integers: 401 and 4011 are
            // different accounts and leading structure matters more than value.
            $table->string('number', 12);
            $table->string('name');
            // 1-8, taken from the first digit. Stored rather than derived so a
            // report can group and sort on it without parsing every row.
            $table->unsignedTinyInteger('class');
            // debit|credit — the side that increases this account. Assets and
            // charges are debit-normal; liabilities, equity and income credit.
            $table->string('normal_balance', 6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'number']);
            $table->index(['company_id', 'class']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            // The journal this belongs to: VE ventes, AC achats, BQ banque,
            // CA caisse, OD opérations diverses.
            $table->string('journal', 4);
            $table->string('reference')->nullable();
            $table->text('narration')->nullable();
            $table->date('entry_date');
            // What produced it, so an entry can be traced back to the invoice
            // or payment it records — and so posting the same source twice is
            // detectable rather than silently doubling the books.
            $table->nullableUlidMorphs('source');
            $table->foreignUlid('reverses_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'entry_date']);
            $table->index(['company_id', 'journal']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('ledger_account_id')->constrained('ledger_accounts')->restrictOnDelete();
            // Both columns rather than one signed amount: it is how a journal
            // is read, how a balance is printed, and it makes "debits equal
            // credits" a sum over two columns instead of a sign convention
            // someone has to remember.
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->string('narration')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['company_id', 'ledger_account_id']);
            $table->index('journal_entry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('ledger_accounts');
    }
};
