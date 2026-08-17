<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One journal entry per source, enforced by the database.
 *
 * Ledger::post's "have I already recorded this" check was a plain SELECT with
 * no lock behind it, so two concurrent replays of the same source (a webhook
 * delivered twice, a queued job retried) could both read nothing and both
 * insert, doubling the books. The application now locks before checking, and
 * this index is the defence that holds even if some future code path forgets
 * to: the second insert fails instead of landing.
 *
 * Reversals post with a null source (Ledger::reverse), and NULLs never
 * collide in a unique index, so entries without a source are unconstrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unique(
                ['company_id', 'source_type', 'source_id'],
                'journal_entries_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_entries_source_unique');
        });
    }
};
