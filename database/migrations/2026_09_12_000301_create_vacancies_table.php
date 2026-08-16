<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A job requisition: "we are hiring for this position".
     *
     * Deliberately thin, because the job itself already exists as a Position
     * (Phase 3.9) and duplicating its title, grade and department here would
     * recreate the free-text drift positions were built to end. A vacancy adds
     * only what recruiting needs on top: how many openings, whether it is
     * live, and the share token the public application page hangs off.
     *
     * The position link restricts rather than nulling out: a vacancy is an
     * advert FOR a position, and an advert for a deleted job is not a record
     * worth keeping ambiguous — close the vacancy first.
     */
    public function up(): void
    {
        Schema::create('vacancies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignUlid('position_id')->constrained('positions')->restrictOnDelete();

            $table->text('description')->nullable();
            $table->unsignedInteger('openings')->default(1);

            // draft → open → closed. Only an open vacancy accepts applications
            // through the public page.
            $table->string('status')->default('draft');

            /*
             * Looked up withoutGlobalScopes by the public controller, exactly
             * as forms' share_token is: the visitor is not a user and the token
             * IS the tenant resolution. Unique globally for that reason.
             */
            $table->string('share_token', 32)->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'position_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacancies');
    }
};
