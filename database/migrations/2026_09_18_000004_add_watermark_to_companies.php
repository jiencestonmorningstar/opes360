<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A background watermark for printed documents — either one of the six
 * generated seal designs (SealCatalog/SealComposer) or a custom uploaded
 * image. `show_watermark` defaults false: existing prints look exactly as
 * they did before this shipped until a business opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('watermark_path')->nullable()->after('logo_original_path');
            $table->string('watermark_seal')->nullable()->after('watermark_path');
            $table->boolean('show_watermark')->default(false)->after('watermark_seal');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['watermark_path', 'watermark_seal', 'show_watermark']);
        });
    }
};
