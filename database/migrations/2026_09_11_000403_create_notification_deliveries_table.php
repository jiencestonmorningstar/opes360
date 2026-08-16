<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every attempt to tell somebody something, including the ones that
     * deliberately said nothing.
     *
     * This table exists to answer "I never got told". Logging only successes
     * would answer it with silence, which is indistinguishable from the bug —
     * so a message suppressed by a mute, dropped as a duplicate, or held for a
     * digest is written here too, with the reason. Support can then say which
     * of the four happened instead of guessing.
     *
     * It doubles as the digest queue: a row with status `deferred` is a
     * message waiting to be released, and the digest command is simply a query
     * over this table. One record of what happened, rather than a log plus a
     * separate queue that can disagree with it.
     */
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            /*
             * Nullable: the automation runner's notify actions come through
             * here too, and they have no notification rule behind them. Set
             * null rather than deleted with the rule — the log has to survive
             * somebody tidying up their rules, or it cannot be evidence.
             */
            $table->foreignUlid('rule_id')->nullable()->constrained('notification_rules')->nullOnDelete();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('event');
            $table->string('category');
            $table->string('severity');
            $table->string('channel');

            // sent | deferred | suppressed | failed
            $table->string('status');

            // muted | duplicate | quiet_hours | digest | channel_unavailable | …
            $table->string('reason')->nullable();

            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();

            // What it was about, as a reference — never a copy of the record.
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();

            /*
             * The deduplication fingerprint. Indexed with the timestamp because
             * every send does one lookup of "this key, since then" and that
             * lookup sits in the request path of every business event.
             */
            $table->string('dedupe_key', 64)->nullable();

            // When a deferred row becomes due for release.
            $table->timestamp('release_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'user_id', 'created_at'], 'notif_deliveries_user_idx');
            $table->index(['dedupe_key', 'created_at'], 'notif_deliveries_dedupe_idx');
            $table->index(['status', 'release_at'], 'notif_deliveries_pending_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
