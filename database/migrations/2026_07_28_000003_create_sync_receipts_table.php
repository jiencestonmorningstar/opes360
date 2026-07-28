<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The idempotency ledger. Every push envelope is recorded by its
         * client-generated id before it is applied; a replay finds the row and
         * returns the original outcome instead of creating a second record.
         *
         * This is what makes "retry safely" true rather than hopeful — a device
         * that loses the connection mid-push has no way to know whether the
         * write landed, so it must be free to send again.
         */
        Schema::create('sync_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();

            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('operation');
            $table->string('status');                 // applied|duplicate|conflict|rejected
            $table->unsignedInteger('server_version')->default(1);
            $table->string('assigned_number')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // The envelope id is globally unique — it is a ULID minted by the
            // device — so uniqueness is enforced on it alone.
            $table->unique('id');
            $table->index(['company_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        // Monotonic per-company cursor so a device can ask "what changed since?"
        // without relying on wall-clock timestamps, which skew between devices.
        Schema::table('contacts', fn (Blueprint $t) => $t->unsignedBigInteger('sync_sequence')->nullable()->index());
        Schema::table('items', fn (Blueprint $t) => $t->unsignedBigInteger('sync_sequence')->nullable()->index());
        Schema::table('documents', fn (Blueprint $t) => $t->unsignedBigInteger('sync_sequence')->nullable()->index());
        Schema::table('payments', fn (Blueprint $t) => $t->unsignedBigInteger('sync_sequence')->nullable()->index());

        Schema::create('sync_sequences', function (Blueprint $table) {
            $table->foreignUlid('company_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_sequences');

        foreach (['contacts', 'items', 'documents', 'payments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('sync_sequence'));
        }

        Schema::dropIfExists('sync_receipts');
    }
};
