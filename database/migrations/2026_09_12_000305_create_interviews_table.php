<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scheduled conversation with a candidate.
     *
     * Who is interviewing lives in interview_feedback, one row per
     * interviewer, created at scheduling time with the verdict blank — the
     * panel and the scorecard are the same list, so there is no way for
     * "who was asked to interview" and "who scored it" to disagree.
     */
    public function up(): void
    {
        Schema::create('interviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignUlid('job_application_id')
                ->constrained('job_applications')->cascadeOnDelete();

            $table->dateTime('scheduled_at');

            // "Our office", "Google Meet", a phone number. Free text: the mode
            // of a small business's interview is not an enum.
            $table->string('location')->nullable();
            $table->text('notes')->nullable();

            // scheduled → completed / cancelled.
            $table->string('status')->default('scheduled');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['company_id', 'job_application_id'],
                'interviews_company_application_index',
            );
            $table->index(['company_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interviews');
    }
};
