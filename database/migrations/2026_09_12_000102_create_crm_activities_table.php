<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM activities: the PLANNED side of selling.
     *
     * Not a second audit log — AuditObserver already records every write to a
     * Deal, and a second change log would disagree with the first. What audit
     * cannot know is intent: the call somebody means to make on Thursday, the
     * meeting that happened but changed no field. Those rows live here, and
     * "which deals has nobody touched" is the query they exist to answer.
     */
    public function up(): void
    {
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // A deal or a lead. Polymorphic because the same "ring them back
            // Thursday" is real on both sides of conversion.
            $table->string('subject_type');
            $table->ulid('subject_id');

            $table->string('kind'); // call | meeting | note | task
            $table->string('summary');
            $table->text('details')->nullable();

            // Planned vs done, as two timestamps rather than a status column:
            // "due and not done" IS overdue, with no third field to fall out
            // of step with the other two.
            $table->dateTime('due_at')->nullable();
            $table->dateTime('done_at')->nullable();

            $table->foreignId('user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id'], 'crm_activities_subject_index');
            // The overdue query: one company's undone work, oldest due first.
            $table->index(['company_id', 'done_at', 'due_at'], 'crm_activities_open_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_activities');
    }
};
