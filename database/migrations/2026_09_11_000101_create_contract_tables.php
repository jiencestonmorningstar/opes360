<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The agreement itself — the commercial terms, not the paper.
         *
         * There is deliberately no `number`, no `body` and no `file_path` here.
         * The signed paper is a `business_documents` row reached through
         * `business_document_relations`, which already carries numbering,
         * versions, e-signature, sharing and retention. A contract that grew
         * its own copy of any of those would be the second document system the
         * brief forbids outright, and the two would disagree within a month.
         */
        Schema::create('contracts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            /*
             * The counterparty is an existing contact. A supplier under
             * contract is the same organisation as the supplier being paid,
             * and a second list of names is how a business ends up chasing an
             * address it corrected six months ago in the other one.
             *
             * Nulled rather than cascaded on delete: tidying a contact list
             * must not silently destroy the record of what was agreed with
             * them. Same reasoning as business_document_relations.
             */
            $table->foreignUlid('contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();

            // Which way the obligation runs: inbound = we buy, outbound = we
            // sell. Kept as its own column rather than inferred from the
            // contact's type, because a customer can also be a supplier and
            // the answer would flip when somebody edited the contact.
            $table->string('direction', 20)->default('inbound');
            $table->string('type', 40)->default('service');

            $table->decimal('value', 16, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->date('starts_on');

            // Null means evergreen — a contract that runs until somebody ends
            // it. Distinct from "we have not filled the date in yet", which is
            // a draft, and the reason nothing here treats a missing end date
            // as an expiry today.
            $table->date('ends_on')->nullable();

            $table->string('renewal_type', 20)->default('none');   // none|auto|manual
            $table->unsignedSmallInteger('renewal_term_months')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();

            /*
             * The last day notice can be given, stored rather than derived.
             *
             * This is the column the whole feature turns on. Computed at read
             * time it would be equally correct and completely useless: it
             * could not be indexed, sorted or queried, so nothing could ask
             * "whose deadline falls this fortnight" without loading every
             * contract in the business. The model keeps it in step with
             * ends_on and notice_period_days on every save, so the two can
             * never drift apart.
             */
            $table->date('notice_by')->nullable();

            // draft|active|expired|terminated. Approval is deliberately not a
            // value here — whether it has been agreed is the workflow engine's
            // answer, and duplicating it would give two places to look and one
            // of them wrong.
            $table->string('status', 20)->default('draft');

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('terminated_on')->nullable();
            $table->text('termination_reason')->nullable();
            $table->foreignId('terminated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status'], 'contracts_company_status_idx');
            $table->index(['company_id', 'notice_by'], 'contracts_company_notice_idx');
            $table->index(['company_id', 'ends_on'], 'contracts_company_ends_idx');
        });

        /*
         * What each side actually has to do, and by when.
         *
         * The value of a contract register is not the PDF; it is knowing that
         * the supplier owes a quarterly report and has not sent one. One table
         * for both sides, distinguished by `owed_by`, because "what have we
         * failed to do" and "what have they failed to do" are the same
         * question asked from two chairs.
         */
        Schema::create('contract_obligations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('contract_id')->constrained('contracts')->cascadeOnDelete();

            $table->string('owed_by', 10)->default('us');   // us|them
            $table->string('title');
            $table->text('description')->nullable();

            // Null means standing — "keep the site tidy" has no date and can
            // never be overdue. Treating it as due today would fill the
            // overdue list with things nobody can tick off.
            $table->date('due_on')->nullable();

            $table->date('completed_on')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('completion_note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'due_on'], 'contract_obligations_company_due_idx');
            $table->index(['company_id', 'contract_id'], 'contract_obligations_company_contract_idx');
        });

        /*
         * Every extension, kept.
         *
         * The contract's own dates answer "what are we on now"; this answers
         * "how many times has this quietly rolled over, and did the price move
         * each time" — which is the question asked when somebody finally looks
         * at a five-year-old cleaning contract and wonders how it got there.
         */
        Schema::create('contract_renewals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('contract_id')->constrained('contracts')->cascadeOnDelete();

            $table->date('previous_ends_on')->nullable();
            $table->date('new_ends_on');
            $table->decimal('previous_value', 16, 2)->nullable();
            $table->decimal('new_value', 16, 2)->nullable();

            // auto = it rolled over under its own terms; negotiated = somebody
            // sat down and agreed it. Worth telling apart: a register full of
            // the first is a register nobody is reading.
            $table->string('method', 20)->default('negotiated');

            $table->date('renewed_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'contract_id'], 'contract_renewals_company_contract_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_renewals');
        Schema::dropIfExists('contract_obligations');
        Schema::dropIfExists('contracts');
    }
};
