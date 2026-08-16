<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // The model class this workflow approves. Polymorphic on purpose:
            // the engine never imports a business model, and a business
            // module never imports the engine's internals.
            $table->string('subject_type');

            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'subject_type', 'is_active']);
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('workflow_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('position');
            $table->string('name');
            $table->string('type')->default('approval');   // review|approval|signature|task

            /*
             * Who acts. Resolved when the step is reached, never when it is
             * defined — a workflow that named a person is wrong the day they
             * leave, and nobody finds out until an invoice has sat unapproved
             * for a week. It names a role or a department; the engine asks who
             * that is now.
             */
            $table->string('approver_mode')->default('role'); // role|department|user|owner|manager|creator
            $table->string('approver_role')->nullable();
            $table->foreignUlid('approver_department_id')->nullable()
                ->constrained('departments')->nullOnDelete();
            $table->foreignId('approver_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // 'all' | 'any' | a number. One field rather than four flags:
            // single, multiple, sequential and parallel all fall out of it.
            $table->string('quorum')->default('any');

            // [{field, operator, value}], evaluated against the subject.
            // Never executable code — see App\Support\WorkflowConditions.
            $table->json('conditions')->nullable();

            $table->unsignedInteger('due_days')->nullable();

            $table->timestamps();

            $table->index(['workflow_id', 'position']);
        });

        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('workflow_id')->constrained()->cascadeOnDelete();

            $table->string('subject_type');
            $table->string('subject_id');

            // running|approved|rejected|changes_requested|stalled|cancelled
            $table->string('status')->default('running');
            $table->unsignedInteger('position')->default(0);

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'subject_type', 'subject_id']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('workflow_assignments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('workflow_instance_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('workflow_step_id')->constrained('workflow_steps')->cascadeOnDelete();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // pending|acted|delegated|superseded
            $table->string('status')->default('pending');
            $table->date('due_on')->nullable();

            // Set when this assignment exists because somebody handed it over.
            $table->foreignId('delegated_from')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // 26 + 26 + 8 bytes. Nowhere near MySQL's 3072-byte index limit.
            $table->unique(['workflow_instance_id', 'workflow_step_id', 'user_id'], 'workflow_assignment_unique');
            $table->index(['company_id', 'user_id', 'status']);
        });

        Schema::create('workflow_decisions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('workflow_instance_id')->constrained()->cascadeOnDelete();

            /*
             * The step is nulled rather than cascaded, and its name is copied
             * onto the decision. Editing a workflow must not rewrite what
             * already happened: "approved by the Finance Manager" has to keep
             * saying that after somebody renames the step.
             */
            $table->foreignUlid('workflow_step_id')->nullable()
                ->constrained('workflow_steps')->nullOnDelete();
            $table->string('step_name');

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // submitted|approved|rejected|changes_requested|delegated|cancelled
            $table->string('action');
            $table->text('comment')->nullable();
            $table->foreignId('delegated_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('acted_at');

            $table->timestamps();

            $table->index(['company_id', 'workflow_instance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_decisions');
        Schema::dropIfExists('workflow_assignments');
        Schema::dropIfExists('workflow_instances');
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflows');
    }
};
