<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Every renewal, kept — the contract_renewals pattern wearing an
         * insurance label. The policy's own dates answer "what term are we
         * on now"; this table answers "how many times has this rolled over,
         * and did the premium move each time" — the question a broker's
         * client asks at exactly the moment nobody can reconstruct it.
         */
        Schema::create('insurance_policy_renewals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('insurance_policy_id')
                ->constrained('insurance_policies', indexName: 'ins_renewals_policy_fk')->cascadeOnDelete();

            $table->date('previous_covers_from')->nullable();
            $table->date('previous_covers_to')->nullable();
            $table->date('new_covers_from');
            $table->date('new_covers_to');

            $table->decimal('previous_premium', 16, 2)->nullable();
            $table->decimal('new_premium', 16, 2)->nullable();

            // auto = it rolled over under its own terms; negotiated =
            // somebody sat down with the client and agreed it.
            $table->string('method', 20)->default('negotiated');

            $table->date('renewed_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Named by hand, under MySQL's 64-character identifier limit.
            $table->index(['company_id', 'insurance_policy_id'], 'ins_renewals_company_policy_idx');
        });

        /*
         * Mid-term changes to cover: the sum insured raised, the vehicle
         * swapped, a driver added. The row records what changed and when it
         * took effect; the money side of the change — an additional or a
         * return premium — is an ordinary sales document (debit or credit
         * note), linked through document_id and never restated here.
         */
        Schema::create('insurance_endorsements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('insurance_policy_id')
                ->constrained('insurance_policies', indexName: 'ins_endorsements_policy_fk')->cascadeOnDelete();

            $table->date('effective_on');
            $table->text('description');

            $table->decimal('previous_premium', 16, 2)->nullable();
            $table->decimal('new_premium', 16, 2)->nullable();

            // Signed: positive means the client owes more (debit note),
            // negative means a return premium (credit note), zero means the
            // change moved no money — a corrected registration plate.
            $table->decimal('premium_delta', 16, 2)->default(0);

            // The debit or credit note that adjusts the premium, once drafted.
            $table->foreignUlid('document_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'insurance_policy_id'], 'ins_endorse_company_policy_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_endorsements');
        Schema::dropIfExists('insurance_policy_renewals');
    }
};
