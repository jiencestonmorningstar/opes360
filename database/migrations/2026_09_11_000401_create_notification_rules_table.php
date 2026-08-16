<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "When X happens, and Y is true, tell Z on channel C."
     *
     * Deliberately the same shape as automation_rules — same event name from
     * the same catalogue, same {field, operator, value} conditions read by the
     * same matcher. A notification rule is an automation rule whose only
     * action is telling somebody, and keeping the shapes identical is what
     * lets one condition language serve both.
     *
     * Index names are given by hand throughout. MySQL rejects an identifier
     * over 64 characters and SQLite accepts it silently, so a generated name
     * like notification_rules_company_id_event_is_active_index passes every
     * test locally and fails on the first real migrate.
     */
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // The trigger: a name from App\Support\DomainEvents.
            $table->string('event');
            $table->json('conditions')->nullable();

            /*
             * The category is what a person mutes. Rules are written by an
             * administrator and muted by a recipient, and those two people are
             * not the same person — so a recipient must be able to say "no
             * more stock alerts" without knowing which rules produce them.
             */
            $table->string('category')->default('operations');

            /*
             * Severity is the escape hatch from every noise control below.
             * `critical` bypasses mutes, digests and quiet hours, because a
             * payment run awaiting a signature is not a thing anyone is
             * allowed to have silently switched off. Everything else is
             * suppressible, which is what makes suppression safe to offer.
             */
            $table->string('severity')->default('normal');

            // What the recipient reads. Written by whoever wrote the rule.
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();

            /*
             * [{mode: role|user|owner|creator|manager|department|permission,
             *   value: …}] — resolved when the rule fires, never stored as a
             * resolved list of ids. A rule naming "the Finance manager" has to
             * mean whoever that is today; a stored id is wrong the day that
             * person leaves and nobody finds out.
             */
            $table->json('recipients');

            // Keys from App\Support\NotificationChannels.
            $table->json('channels');

            /*
             * How long the same message about the same record stays silent.
             * A status field touched five times in a minute is five events and
             * one piece of news; without this the recipient gets five alerts
             * and mutes the category, and a muted channel is worse than none.
             */
            $table->unsignedInteger('dedupe_minutes')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_fired_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'event', 'is_active'], 'notif_rules_company_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
