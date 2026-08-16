<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a person wants to hear about, and how.
     *
     * One row per scope, most specific wins. A row with an empty category and
     * an empty channel is the blanket setting for that person in that
     * business; filling in a category narrows it; filling in both narrows it
     * again. That single hierarchy covers "mute everything", "mute stock",
     * and "stock by email only, not in the bell" without three tables.
     *
     * Empty string, not null, for "applies to everything". MySQL treats two
     * NULLs as distinct in a unique index, so a nullable column here would let
     * the same person accumulate twenty conflicting blanket rows and the
     * resolver would pick whichever came back first.
     */
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // users.id is a BIGINT — foreignId, not foreignUlid.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('category')->default('');
            $table->string('channel')->default('');

            /*
             * Null means "inherit from the next-broadest row", which is not the
             * same as false. Without the distinction, a narrow row created to
             * set quiet hours would also silently switch the category on.
             */
            $table->boolean('enabled')->nullable();

            // immediate | digest. Null inherits, as above.
            $table->string('mode')->nullable();

            /*
             * Quiet hours defer, they never drop. Somebody who asked not to be
             * pinged at 23:00 has not asked to never be told — the message is
             * held and released with the next digest run.
             */
            $table->time('quiet_from')->nullable();
            $table->time('quiet_to')->nullable();

            $table->timestamps();

            $table->unique(
                ['company_id', 'user_id', 'category', 'channel'],
                'notif_prefs_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
