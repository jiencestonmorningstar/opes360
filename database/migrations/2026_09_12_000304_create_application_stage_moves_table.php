<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who moved an application, from where to where, and when.
     *
     * Append-only. The application's `stage` column answers "where is it
     * now"; this table answers the question a dispute actually asks — "who
     * decided, and when" — which a single overwritten column cannot. A
     * rejection's reason is captured on the move as well as the application,
     * so rejecting, un-rejecting and rejecting again keeps every reason.
     *
     * Cascades with the application: the history is ABOUT that one run and
     * nobody else, the same call attendance made for a force-deleted
     * employee. This is also what lets a GDPR purge be complete.
     */
    public function up(): void
    {
        Schema::create('application_stage_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignUlid('job_application_id')
                ->constrained('job_applications')->cascadeOnDelete();

            $table->string('from_stage')->nullable(); // null = the original application
            $table->string('to_stage');
            $table->string('reason')->nullable();

            // Null for the applicant's own submission through the public page.
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['company_id', 'job_application_id'],
                'stage_moves_company_application_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_stage_moves');
    }
};
