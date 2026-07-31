<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Null means 'classic'. Validated at the model layer, so a value
            // this build no longer knows degrades to classic instead of a
            // broken card.
            $table->string('card_design')->nullable()->after('brand_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('card_design');
        });
    }
};
