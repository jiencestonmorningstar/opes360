<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Money moved between the business's own accounts — bank to cash for
         * a float, cash to bank for a deposit, one bank to another.
         *
         * A transfer is deliberately its own record rather than two loose
         * journal entries. Both sides move at once or neither does, and the
         * pairing has to survive: "why is there 200,000 less in the bank on
         * the 3rd" is answerable only if the withdrawal and the deposit are
         * visibly one act rather than two coincidences.
         */
        Schema::create('account_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Both sides are ledger accounts, not bank accounts: cash (571)
            // is a ledger account with no bank_accounts row, and a float
            // drawn from the bank into the till is the commonest transfer a
            // small business makes.
            $table->foreignUlid('from_account_id')->constrained('ledger_accounts')->cascadeOnDelete();
            $table->foreignUlid('to_account_id')->constrained('ledger_accounts')->cascadeOnDelete();

            $table->date('transferred_on');
            $table->decimal('amount', 14, 2);
            $table->string('reference')->nullable();
            $table->text('narration')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'transferred_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_transfers');
    }
};
