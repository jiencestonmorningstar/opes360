<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A fiscal year and the periods inside it. ERP checklist #1.
         *
         * Deliberately not derived from the calendar: a business's financial
         * year does not have to start in January, and several of the ones
         * this product serves close in June or September. The dates are the
         * business's own.
         */
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');          // "2026", "FY 2026/27"
            $table->date('starts_on');
            $table->date('ends_on');

            // open|closed. A closed year cannot be reopened without an
            // explicit act — see FiscalPeriods::reopenYear().
            $table->string('status')->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'starts_on']);
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('fiscal_year_id')->constrained()->cascadeOnDelete();

            $table->string('name');          // "January", "Q1"
            $table->date('starts_on');
            $table->date('ends_on');

            $table->string('status')->default('open');   // open|closed
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'starts_on', 'ends_on'], 'fiscal_periods_company_range_idx');
            $table->index(['company_id', 'status']);
        });

        /*
         * Cost centres — the other half of #1's missing piece. A department
         * answers "who does this belong to"; a cost centre answers "which
         * budget does this come out of", and a business often wants both to
         * differ (one department running two funded projects).
         */
        Schema::create('cost_centres', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code');
            $table->string('name');
            $table->foreignUlid('department_id')->nullable()
                ->constrained('departments')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
        });

        Schema::table('journal_lines', function (Blueprint $table) {
            $table->foreignUlid('cost_centre_id')->nullable()
                ->constrained('cost_centres')->nullOnDelete();

            $table->index(['company_id', 'cost_centre_id'], 'journal_lines_company_cost_centre_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cost_centre_id');
        });

        Schema::dropIfExists('cost_centres');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('fiscal_years');
    }
};
