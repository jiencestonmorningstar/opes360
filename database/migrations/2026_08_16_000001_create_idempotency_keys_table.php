<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Making a retry safe.
     *
     * A client that POSTs a payment and then loses the connection cannot know
     * whether the money was taken. Its only options are to retry — and risk
     * charging twice — or not to retry, and risk a payment the business never
     * recorded. Both are wrong, and on a mobile network in this market the
     * situation is routine rather than exotic.
     *
     * So the client sends an `Idempotency-Key` it generated, and a second
     * request carrying the same key gets the first one's response back instead
     * of doing the work again.
     *
     * The key is scoped to the token, not just to the company: two integrations
     * that happen to generate the same UUID must not read each other's replies.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('key');
            $table->unsignedBigInteger('token_id')->nullable();

            $table->string('method', 10);
            $table->string('path');

            /*
             * A hash of the body the first request carried. The same key with a
             * different payload is a client bug — reusing a key for a second,
             * genuinely different payment — and answering it with the first
             * one's response would hide that. It is refused instead.
             */
            $table->string('request_hash', 64);

            $table->unsignedSmallInteger('status')->nullable();
            $table->longText('response')->nullable();

            /*
             * Set when the work finishes. A row that exists with no response
             * yet means a request is in flight, so a retry that arrives while
             * the first is still running is told to wait rather than being let
             * through to take the money a second time.
             */
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The uniqueness that does the actual work.
            $table->unique(['company_id', 'token_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
