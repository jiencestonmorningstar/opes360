<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Which of the letterhead designs this business prints on. Null
            // means the original look ('rule'), so existing stationery keeps
            // printing exactly as it always has.
            $table->string('letterhead_design')->nullable()->after('brand_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('letterhead_design');
        });
    }
};
