<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person who applied, kept apart from their applications.
     *
     * One person can apply to two vacancies, and merging "the person" into
     * "the application" would store their name and phone twice and let the
     * two copies disagree. The person also outlives the pipeline: the one who
     * is hired becomes an Employee, and `employee_id` records which one —
     * pointing candidate → employee rather than the other way so the staff
     * file never grows a recruitment column it does not need.
     *
     * Soft deletes are the GDPR posture: a rejected candidate's data can be
     * taken off every screen immediately, and purged for real later. See
     * RecruitmentPipeline::purge().
     */
    public function up(): void
    {
        Schema::create('candidates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->string('email', 180)->nullable();
            $table->string('phone', 40)->nullable();

            // Where they heard about the job — free text on purpose; sources
            // are "my cousin", "the sign outside", not an enumerable list.
            $table->string('source', 120)->nullable();

            /*
             * The employee this candidate became, if hired. Nulls on delete:
             * purging an ex-employee must never take the recruitment history's
             * integrity with it, and vice versa.
             */
            $table->foreignUlid('employee_id')->nullable()
                ->constrained('employees')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'email']);
            $table->index(['company_id', 'last_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidates');
    }
};
