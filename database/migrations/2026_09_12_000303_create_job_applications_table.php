<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One candidate's run at one vacancy — the thing the pipeline moves.
     *
     * `job_applications`, not `recruitment_applications`: the longer prefix
     * pushes generated index and FK identifiers toward MySQL's 64-character
     * limit (the trap performance_reviews already documented), and the short
     * name loses nothing.
     *
     * The CV lives on the private `documents` disk with its path recorded
     * here rather than through a Media row: a Media row belongs to a managed
     * BusinessDocument with an owner and a policy, and a public applicant has
     * neither. What is reused is the part that matters — the private disk
     * with no URL, and the same extension+mime allow-list discipline as
     * DocumentFiler. See RecruitmentPipeline::storeCv().
     */
    public function up(): void
    {
        Schema::create('job_applications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignUlid('vacancy_id')->constrained('vacancies')->cascadeOnDelete();
            $table->foreignUlid('candidate_id')->constrained('candidates')->cascadeOnDelete();

            // applied → screening → interview → offer → hired, with rejected
            // reachable from anywhere. History lives in application_stage_moves.
            $table->string('stage')->default('applied');

            $table->text('cover_note')->nullable();
            $table->string('rejection_reason')->nullable();

            $table->string('cv_disk', 40)->nullable();
            $table->string('cv_path', 500)->nullable();
            $table->string('cv_name')->nullable();
            $table->string('cv_mime', 120)->nullable();
            $table->unsignedBigInteger('cv_size')->nullable();

            // Null when the application came through the public page — there
            // is no user to name, and inventing one would be a lie.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The same person applying twice to the same vacancy is a re-send,
            // not a second application; the writing code updates instead.
            $table->unique(['vacancy_id', 'candidate_id']);
            $table->index(['company_id', 'stage']);
            $table->index(['company_id', 'vacancy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_applications');
    }
};
