<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A customer's request for help.
         *
         * Named `service_tickets` because `tickets` is already taken by event
         * ticketing, and merging the two would put a concert admission and a
         * broken generator on the same list.
         *
         * Note what is not here: the customer's name, phone or address. The
         * customer is a `contacts` row. A service desk that keeps its own
         * copy is the second customer list the brief forbids, and it starts
         * disagreeing with the first one within a week.
         */
        Schema::create('service_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // TKT-2026-00001, drawn from the existing lease ledger.
            $table->string('reference')->nullable();

            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('subject');
            $table->text('description')->nullable();

            $table->string('priority')->default('normal');   // low|normal|high|urgent
            $table->string('channel')->default('phone');     // phone|email|walk_in|portal|whatsapp|internal
            $table->string('category')->nullable();          // the business's own vocabulary

            // new|open|pending_customer|resolved|closed|cancelled
            $table->string('status')->default('new');

            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();

            // The machine it is about, and the contract it is under. Both
            // point at records that already exist elsewhere.
            $table->foreignUlid('fixed_asset_id')->nullable()->constrained('fixed_assets')->nullOnDelete();
            $table->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();

            $table->foreignUlid('sla_policy_id')->nullable()
                ->constrained('service_sla_policies')->nullOnDelete();

            /*
             * The clock.
             *
             * The two due-at columns are absolute instants, already worked out
             * through the policy's working calendar. That is the whole design
             * decision: the calendar arithmetic happens once, when something
             * changes, so that "what is breaching" is a `where due_at < now`
             * that an index can answer — rather than a loop that loads every
             * open ticket and asks a calendar object about each one.
             */
            $table->timestamp('opened_at');
            $table->timestamp('response_due_at')->nullable();
            $table->timestamp('resolution_due_at')->nullable();

            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            /*
             * Working minutes the clock has been stopped for — waiting on the
             * customer, or sitting resolved before being reopened. Deadlines
             * are always recomputed as opened_at + target + this, so the same
             * formula covers a pause, an escalation and a reopening, and no
             * amount of repeating it can drift.
             */
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_minutes')->default(0);

            // Set when a breach has been announced, so a nightly sweep does
            // not shout about the same ticket every night.
            $table->timestamp('breach_notified_at')->nullable();

            $table->text('resolution')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Named explicitly: the generated names run past MySQL's 64-char
            // identifier limit, which SQLite accepts and MySQL refuses.
            $table->unique(['company_id', 'reference'], 'service_tickets_reference_unq');
            $table->index(['company_id', 'status', 'priority'], 'service_tickets_queue_idx');
            $table->index(['company_id', 'status', 'response_due_at'], 'service_tickets_response_due_idx');
            $table->index(['company_id', 'status', 'resolution_due_at'], 'service_tickets_resolution_due_idx');
            $table->index(['company_id', 'assignee_id', 'status'], 'service_tickets_assignee_idx');
            $table->index(['company_id', 'contact_id'], 'service_tickets_customer_idx');
        });

        /*
         * Everything that happened to a ticket, in order and append-only.
         *
         * The clock's audit trail. A deadline that moved for a reason nobody
         * can reconstruct is a deadline the customer will argue with and the
         * desk cannot defend — so every pause, resume, escalation and
         * reopening writes the minutes it credited and who did it.
         */
        Schema::create('service_ticket_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('ticket_id')->constrained('service_tickets')->cascadeOnDelete();

            // opened|assigned|responded|paused|resumed|escalated|resolved|reopened|closed|note
            $table->string('kind');

            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();

            // Working minutes this event gave back to the clock, if any.
            $table->integer('clock_minutes')->nullable();

            $table->text('note')->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');

            $table->timestamps();

            $table->index(['company_id', 'ticket_id', 'occurred_at'], 'service_ticket_events_feed_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_ticket_events');
        Schema::dropIfExists('service_tickets');
    }
};
