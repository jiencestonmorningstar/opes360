<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A per-deal win probability, for the sales forecast.
     *
     * Nullable on purpose: the stage carries a sensible default (see
     * Deal::STAGE_PROBABILITIES), and this column exists only for the deal
     * somebody knows better about — "it's at proposal but they've told us
     * verbally we've won". Null means "trust the stage", which is what nearly
     * every deal should do.
     */
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedTinyInteger('probability')->nullable()->after('value');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('probability');
        });
    }
};
