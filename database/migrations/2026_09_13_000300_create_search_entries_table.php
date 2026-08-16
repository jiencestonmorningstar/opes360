<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per searchable record: a denormalised, permission-tagged copy of
 * whatever a record calls itself, kept current by observers so the palette
 * answers with a single indexed LIKE instead of a union over ten tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            // The record this row stands for. String id because most business
            // tables key on ULIDs; a bigint id stringifies losslessly.
            $table->string('searchable_type');
            $table->string('searchable_id', 40);

            $table->string('title');
            $table->string('subtitle')->nullable();
            // Deliberately absent for anything carrying a security level.
            $table->text('body')->nullable();

            // Where a click goes, and the page-level ability that gates it.
            // The ability is checked again at query time — the index merely
            // remembers which question to ask.
            $table->string('route_name');
            $table->json('route_params');
            $table->string('ability');

            $table->timestamps();

            $table->unique(['searchable_type', 'searchable_id'], 'search_entries_searchable_unique');
            $table->index(['company_id', 'title'], 'search_entries_company_title_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_entries');
    }
};
