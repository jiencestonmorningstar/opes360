<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A visit: somebody going somewhere at a time to do something about a
         * ticket. One ticket can need three of them.
         *
         * A job carries no customer of its own — that is on the ticket — and
         * no invoice of its own: `document_id` points at an ordinary sales
         * invoice in the `documents` table, created through the path that
         * already exists.
         */
        Schema::create('service_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            /*
             * Nullable, so planned maintenance that nobody complained about
             * can still be scheduled as a visit. The common case is a ticket.
             */
            $table->foreignUlid('ticket_id')->nullable()
                ->constrained('service_tickets')->nullOnDelete();

            $table->string('reference')->nullable();

            $table->foreignId('technician_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('scheduled_for')->nullable();
            $table->unsignedInteger('estimated_minutes')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // scheduled|in_progress|completed|cancelled
            $table->string('status')->default('scheduled');

            $table->text('on_site_notes')->nullable();
            $table->text('customer_signature_name')->nullable();

            // The machine worked on, and — where this visit *is* the service
            // the asset register was already expecting — the maintenance
            // record it satisfies. Servicing is recorded once, by the module
            // that owns it; this only points at it.
            $table->foreignUlid('fixed_asset_id')->nullable()
                ->constrained('fixed_assets')->nullOnDelete();
            $table->foreignUlid('asset_maintenance_id')->nullable()
                ->constrained('asset_maintenance')->nullOnDelete();

            $table->boolean('is_billable')->default(true);

            // The invoice this visit ended up on. A link, never a copy.
            $table->foreignUlid('document_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'reference'], 'service_jobs_reference_unq');
            $table->index(['company_id', 'status', 'scheduled_for'], 'service_jobs_schedule_idx');
            $table->index(['company_id', 'technician_id', 'scheduled_for'], 'service_jobs_technician_idx');
            $table->index(['company_id', 'ticket_id'], 'service_jobs_ticket_idx');
        });

        /*
         * What was fitted.
         *
         * Priced on the line rather than read off the catalogue when the
         * invoice is drawn, for the reason time entries already carry their
         * own rate: a price list that changes in March must not restate what
         * January's job cost.
         *
         * This deliberately does not move stock. The stock ledger is
         * append-only and owned by Inventory; a van stock issue belongs
         * there, and writing movements from here would give one physical
         * count two owners.
         */
        Schema::create('service_job_parts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('service_job_id')->constrained('service_jobs')->cascadeOnDelete();

            // Nullable: a hose cut to length on the van has no catalogue row,
            // and refusing to record it would mean it never got billed.
            $table->foreignUlid('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('description')->nullable();

            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit')->default('unit');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->boolean('is_billable')->default(true);

            $table->timestamps();

            $table->index(['company_id', 'service_job_id'], 'service_job_parts_job_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_job_parts');
        Schema::dropIfExists('service_jobs');
    }
};
