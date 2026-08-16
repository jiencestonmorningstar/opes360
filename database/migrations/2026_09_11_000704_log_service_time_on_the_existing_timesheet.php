<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service jobs use the timesheet that already exists.
 *
 * A technician's three hours on site is the same fact as a consultant's three
 * hours on a project: one person, one day, so many hours, billable at a rate,
 * frozen once billed. Building `service_job_time_entries` beside
 * `project_time_entries` would mean two answers to "how many hours did Aïcha
 * work in September", two locking rules, and two things to change the day
 * timesheet approval is wired up.
 *
 * So the table learns one nullable column, and `project_id` becomes nullable
 * — because a service visit for a walk-in customer belongs to no project, and
 * the alternative is inventing a hidden project to hang it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_time_entries', function (Blueprint $table) {
            $table->foreignUlid('service_job_id')->nullable()->after('project_id')
                ->constrained('service_jobs')->nullOnDelete();

            $table->index(['company_id', 'service_job_id'], 'project_time_entries_job_idx');
        });

        Schema::table('project_time_entries', function (Blueprint $table) {
            $table->ulid('project_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('project_time_entries', function (Blueprint $table) {
            $table->dropIndex('project_time_entries_job_idx');
            $table->dropConstrainedForeignId('service_job_id');
        });
    }
};
