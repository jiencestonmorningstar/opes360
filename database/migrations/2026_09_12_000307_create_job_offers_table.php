<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An offer of employment made against an application.
     *
     * The letter itself is a BusinessDocument generated from the existing
     * `offer_letter` template — `business_document_id` points at it. A second
     * letter generator inside recruitment is exactly the duplication the
     * document module exists to prevent.
     *
     * Approval goes through the shared WorkflowEngine (the JobOffer model is
     * Approvable); `status` here only mirrors the verdict so lists can read
     * one column. The engine's instance stays the authority — accept() checks
     * isApproved(), never this column alone.
     */
    public function up(): void
    {
        Schema::create('job_offers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignUlid('job_application_id')
                ->constrained('job_applications')->cascadeOnDelete();

            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->date('starts_on');

            // draft → pending → approved → accepted / declined; rejected if
            // the workflow says no, withdrawn if the business pulls it.
            $table->string('status')->default('draft');

            $table->foreignUlid('business_document_id')->nullable()
                ->constrained('business_documents')->nullOnDelete();

            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->string('decline_reason')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['company_id', 'job_application_id'],
                'job_offers_company_application_index',
            );
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_offers');
    }
};
