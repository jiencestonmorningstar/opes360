<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An appraisal of one person over one period.
     *
     * The table is `performance_reviews`, not `employee_performance_reviews`:
     * the longer name puts its generated index and foreign-key identifiers
     * past MySQL's 64-character limit, which SQLite would have accepted
     * silently until the first real deployment.
     */
    public function up(): void
    {
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();

            // The job held during the period, which is not necessarily the job
            // held today — a review of someone since promoted must still read
            // against what they were doing at the time.
            $table->foreignUlid('position_id')->nullable()
                ->constrained('positions')->nullOnDelete();

            // users, not employees: the person writing the appraisal is acting
            // as a login. BIGINT, so foreignId.
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('period_starts_on');
            $table->date('period_ends_on');

            $table->string('cycle')->default('annual');   // annual|mid_year|quarterly|probation
            $table->string('status')->default('draft');   // draft|shared|acknowledged

            // 1..5. Nullable while the appraisal is still being written.
            $table->unsignedTinyInteger('overall_rating')->nullable();

            $table->text('summary')->nullable();
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->text('goals')->nullable();

            // The employee's own words, which stay editable after they have
            // acknowledged the verdict even though the verdict does not.
            $table->text('employee_comment')->nullable();

            $table->dateTime('shared_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Named explicitly for the same 64-character reason as above.
            $table->index(['company_id', 'employee_id'], 'reviews_company_employee_index');
            $table->index(['company_id', 'status'], 'reviews_company_status_index');
            $table->index(['company_id', 'period_starts_on'], 'reviews_company_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
    }
};
