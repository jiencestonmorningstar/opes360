<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('code')->nullable();
            $table->string('name');
            $table->text('description')->nullable();

            /*
             * The client, pointing at the existing contacts table. Projects
             * does not get a customer list of its own — that is the CRM's, and
             * a second one is exactly what the brief forbids.
             */
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();

            // planning|active|on_hold|completed|cancelled
            $table->string('status')->default('planning');

            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();        // planned
            $table->date('closed_on')->nullable();      // actual

            $table->decimal('budget', 14, 2)->nullable();

            /*
             * Whether work on this project is chargeable. Internal projects —
             * "move the office", "ISO certification" — cost money and earn
             * none, and a profitability report that treats them as losses is a
             * report nobody trusts.
             */
            $table->boolean('is_billable')->default(true);
            $table->decimal('default_hourly_rate', 14, 2)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'contact_id']);
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['company_id', 'project_id']);
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();

            // A task outlives the milestone it was grouped under. Deleting a
            // milestone must not delete the work.
            $table->foreignUlid('milestone_id')->nullable()
                ->constrained('project_milestones')->nullOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('todo');      // todo|in_progress|blocked|done
            $table->string('priority')->default('normal');  // low|normal|high|urgent

            $table->date('due_on')->nullable();
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'project_id', 'status']);
            $table->index(['company_id', 'assignee_id', 'status']);
        });

        Schema::create('project_time_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('task_id')->nullable()
                ->constrained('project_tasks')->nullOnDelete();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->date('worked_on');
            $table->decimal('hours', 6, 2);
            $table->text('notes')->nullable();

            /*
             * Billability and rate are copied onto the entry, not read from
             * the project when a report runs. A rate that changes in March
             * must not silently restate what January cost — the same reasoning
             * payroll already uses for reading the contract in force on the
             * payslip's own date.
             */
            $table->boolean('is_billable')->default(true);
            $table->decimal('hourly_rate', 14, 2)->nullable();

            // Set once the entry has been billed or a period closed, after
            // which it stops being editable.
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'project_id', 'worked_on']);
            $table->index(['company_id', 'user_id', 'worked_on']);
        });

        /*
         * What a project costs and earns, pointed at the records that already
         * exist. No copies: an expense stays an expense and an invoice stays
         * an invoice, they simply learn which project they belong to.
         */
        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignUlid('project_id')->nullable()->after('supplier_id')
                ->constrained('projects')->nullOnDelete();

            $table->index(['company_id', 'project_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignUlid('project_id')->nullable()
                ->constrained('projects')->nullOnDelete();

            $table->index(['company_id', 'project_id']);
        });

        // Filing, on the same allow-list reasoning folder_id already lives
        // under: a document can belong to a project without that being an edit.
        Schema::table('business_documents', function (Blueprint $table) {
            $table->foreignUlid('project_id')->nullable()->after('department_id')
                ->constrained('projects')->nullOnDelete();

            $table->index(['company_id', 'project_id']);
        });

        Schema::table('business_document_folders', function (Blueprint $table) {
            $table->foreignUlid('project_id')->nullable()->after('department_id')
                ->constrained('projects')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_document_folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::dropIfExists('project_time_entries');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('projects');
    }
};
