<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The cover a broker has placed for a client.
         *
         * Deliberately thin over the platform: the policyholder and the
         * insurer are both existing contacts, the premium is collected through
         * an ordinary sales invoice, the schedule and the wording are managed
         * business documents. This row holds the commercial facts — who is
         * covered, by whom, for what, until when — and above all the dates,
         * because a broker's expensive failure is not a lost PDF; it is cover
         * that ran out before anybody asked the client about renewing.
         *
         * `policy_number` is the *insurer's* reference, copied off their
         * schedule. It is not this system's numbering — Documents numbers what
         * this business issues, and a policy is issued by somebody else.
         */
        Schema::create('insurance_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('policy_number')->nullable();

            /*
             * Both parties are contacts, nulled rather than cascaded on
             * delete: tidying the customer list must not destroy the record of
             * what cover was placed. Same reasoning as contracts.contact_id.
             */
            $table->foreignUlid('holder_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();
            $table->foreignUlid('insurer_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();

            $table->string('product_line', 40)->default('other');

            $table->decimal('premium', 16, 2)->nullable();
            $table->string('currency', 3)->nullable();

            // What the insurer owes the broker for placing it, as agreed in
            // the agency terms. A percentage, not an amount: the amount is a
            // receivable row in policy_commissions when it is earned.
            $table->decimal('commission_percent', 5, 2)->nullable();

            $table->date('covers_from');

            // Null means open cover — rare, but marine open covers and some
            // group schemes genuinely run until cancelled, and pretending they
            // expire today would fill the watchlist with noise.
            $table->date('covers_to')->nullable();

            $table->string('renewal_type', 20)->default('manual');   // none|auto|manual
            $table->unsignedSmallInteger('renewal_term_months')->nullable();
            $table->unsignedSmallInteger('notice_period_days')->nullable();

            /*
             * The last day to act before renewal decides itself, stored rather
             * than derived — the same pattern, for the same reason, as
             * contracts.notice_by: computed at read time it could not be
             * indexed or queried, so nothing could ask "whose deadline falls
             * this fortnight" without loading every policy in the book. The
             * model recomputes it from covers_to and notice_period_days on
             * every save, so the two can never drift apart.
             */
            $table->date('notice_by')->nullable();

            // draft|active|expired|cancelled. Nothing about approval here —
            // if a business routes policies through a workflow, the engine's
            // record is the answer, not a second column.
            $table->string('status', 20)->default('draft');

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('cancelled_on')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Named by hand: the generated names on this table run past
            // MySQL's 64-character identifier limit.
            $table->index(['company_id', 'status'], 'ins_policies_company_status_idx');
            $table->index(['company_id', 'covers_to'], 'ins_policies_company_covers_idx');
            $table->index(['company_id', 'notice_by'], 'ins_policies_company_notice_idx');
        });

        /*
         * Which sales invoices collect this policy's premium.
         *
         * A link, never a copy. The invoice itself is an ordinary `documents`
         * row — numbered, dunned, receipted and aged by the machinery every
         * other invoice uses — and this table only remembers that it belongs
         * to a policy. A policy billed by instalments has several rows here,
         * which is why this is a table rather than a document_id column.
         */
        Schema::create('insurance_policy_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('insurance_policy_id')
                ->constrained('insurance_policies', indexName: 'ins_policy_invoices_policy_fk')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['insurance_policy_id', 'document_id'], 'ins_policy_invoices_unique');
        });

        /*
         * A claim, from first notification to a decision.
         *
         * The evidence — photos, assessor's report, the repudiation letter —
         * is managed business documents linked through the shared relations
         * table, not files of its own. The settlement decision is the workflow
         * engine's: there is no approved_by column here on purpose, because
         * the engine's decision log is the record an auditor reads.
         */
        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('insurance_policy_id')
                ->constrained('insurance_policies', indexName: 'ins_claims_policy_fk')->cascadeOnDelete();

            // The insurer's claim reference, once they issue one.
            $table->string('claim_number')->nullable();

            $table->date('incident_on');
            $table->date('reported_on')->nullable();
            $table->text('description');

            // fnol|assessed|settled|rejected — the broker's view of where the
            // claim stands with the insurer.
            $table->string('status', 20)->default('fnol');

            $table->decimal('claimed_amount', 16, 2)->nullable();
            $table->decimal('settled_amount', 16, 2)->nullable();
            $table->date('settled_on')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->text('assessment_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status'], 'ins_claims_company_status_idx');
            $table->index(['company_id', 'insurance_policy_id'], 'ins_claims_company_policy_idx');
        });

        /*
         * What the insurer owes the broker for a placed policy.
         *
         * A receivable against the insurer contact — not a second ledger. The
         * row records that commission was earned; collecting it is an ordinary
         * invoice to the insurer, linked through document_id, which then ages,
         * dunns and receipts like any other money the business is owed.
         */
        Schema::create('policy_commissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('insurance_policy_id')
                ->constrained('insurance_policies', indexName: 'policy_commissions_policy_fk')->cascadeOnDelete();

            // Denormalised from the policy at the moment of earning, so the
            // receivable still names its debtor if the policy's insurer is
            // later corrected.
            $table->foreignUlid('insurer_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();

            $table->decimal('amount', 16, 2);
            $table->string('currency', 3)->nullable();
            $table->date('earned_on');
            $table->string('description')->nullable();

            // The invoice that bills it, once raised. Null means earned but
            // not yet invoiced — the list a broker reads at month end.
            $table->foreignUlid('document_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'insurance_policy_id'], 'policy_commissions_company_pol_idx');
            $table->index(['company_id', 'document_id'], 'policy_commissions_company_doc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_commissions');
        Schema::dropIfExists('insurance_claims');
        Schema::dropIfExists('insurance_policy_invoices');
        Schema::dropIfExists('insurance_policies');
    }
};
