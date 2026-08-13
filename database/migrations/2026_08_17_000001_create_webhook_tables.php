<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Telling integrations what happened, instead of making them ask.
     *
     * Everything the API offers today is pull. An integration that wants to
     * know when an invoice is issued has one option: ask again, and keep
     * asking. On the connections this product runs on that is expensive for
     * the caller and rude to the server — a stock system polling every minute
     * makes 1,440 requests a day to learn about the four sales that happened,
     * and still learns about each of them up to a minute late.
     *
     * A webhook inverts it. The business registers a URL, names the moments it
     * cares about, and we post to it when one occurs.
     *
     * ── Why two tables ──────────────────────────────────────────────────────
     *
     * The endpoint is configuration: a URL somebody typed once and expects to
     * find again. The delivery is an event in the world: an attempt that
     * succeeded, or failed, at a particular time with a particular response.
     * Keeping the attempt history is not bookkeeping for its own sake — it is
     * the only way to answer the question this feature will actually generate,
     * which is "you say you sent it, my system never got it".
     *
     * ── The secret ──────────────────────────────────────────────────────────
     *
     * Stored in the clear rather than hashed, unlike an API token, and that is
     * deliberate: both sides need the same bytes to compute the same HMAC, so
     * there is nothing to compare a hash against. It is still shown to the
     * user only once, at creation — not because we cannot show it again but
     * because a secret that is idly re-readable on a settings screen is a
     * secret that gets copied into a chat window.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('url', 500);

            // Shared, not hashed — see the class docblock.
            $table->string('secret', 80);

            $table->string('description', 180)->nullable();

            /*
             * The events this endpoint asked for. A list rather than a
             * subscription table because it is read on every business event
             * and written about once a year: normalising it would buy a join
             * on the hot path to save nothing on the cold one.
             */
            $table->json('events');

            $table->boolean('is_active')->default(true);

            /*
             * How many attempts in a row have failed, and when we gave up.
             * An endpoint whose owner tore down the server behind it would
             * otherwise generate deliveries forever, and each one costs a
             * queue worker ten seconds of waiting for a connection nobody is
             * listening on. After enough failures we stop and say why, which
             * is the difference between "your webhooks are off" and "your
             * webhooks are off because your server refused us 15 times".
             */
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 300)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();

            $table->string('event', 60);

            /*
             * The body, stored rather than rebuilt. A redelivery a day later
             * must send byte-for-byte what the first attempt sent: the record
             * it describes may have changed since, and a "redelivery" that
             * quietly carried newer data would make the receiving system's
             * history disagree with ours in a way neither side could see.
             */
            $table->json('payload');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('status', 20)->default('pending');

            $table->unsignedSmallInteger('response_status')->nullable();

            /*
             * Truncated on the way in — see WebhookDelivery::TRUNCATE_BODY_AT.
             * A misconfigured endpoint answers a webhook with a full HTML
             * error page, and storing megabytes of somebody else's stack trace
             * per attempt is how this table becomes the largest one in the
             * database. The first couple of kilobytes are what a person
             * debugging this actually reads.
             */
            $table->text('response_body')->nullable();
            $table->string('last_error', 500)->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['webhook_endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
