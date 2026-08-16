<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_activities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();

            /*
             * Optional. Most chasing is about the account rather than about one
             * invoice — "when are you paying us?" — and forcing a document onto
             * every note would make people pick one at random, which is worse
             * than not knowing.
             */
            $table->foreignUlid('document_id')->nullable()->constrained('documents')->nullOnDelete();
            // foreignId, not foreignUlid: users.id is a bigint. A ULID column
            // pointing at it is rejected outright by MySQL and accepted
            // silently by SQLite, so the tests passed and the deploy would
            // not have.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // call | email | visit | note | promise | dispute
            $table->string('kind', 20);
            $table->text('body')->nullable();

            /*
             * A promise to pay, which is the single most useful thing a
             * collections process records. It is what turns a queue sorted by
             * age into one sorted by who actually needs ringing: somebody who
             * said Friday should not be chased on Thursday, and somebody whose
             * Friday has passed should be chased before anybody else.
             */
            $table->date('promised_at')->nullable();
            $table->decimal('promised_amount', 14, 2)->nullable();

            // When the conversation happened, which is not always when it was
            // typed in — collectors write up an afternoon of calls at five.
            $table->timestamp('happened_at');

            $table->timestamps();

            // Named explicitly: the auto-generated names for a table with this
            // long a prefix run past MySQL's 64-character identifier limit,
            // which SQLite accepts silently and MySQL refuses at deploy.
            $table->index(['company_id', 'happened_at'], 'coll_act_company_happened_idx');
            $table->index(['contact_id', 'happened_at'], 'coll_act_contact_happened_idx');
            $table->index(['company_id', 'promised_at'], 'coll_act_company_promised_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_activities');
    }
};
