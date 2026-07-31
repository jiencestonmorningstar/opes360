<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel's standard notifications table (in-app channel). Kept
     * hand-written rather than via `notifications:table` so it lives in
     * sequence with the rest of the schema.
     *
     * No company_id: a notification belongs to the user who receives it, and
     * a user can act for several companies. Which business it concerns is
     * carried inside `data` instead, read when rendering the bell.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
