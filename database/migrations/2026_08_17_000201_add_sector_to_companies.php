<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which sector a business said it was in at signup.
 *
 * Purely a record of what was answered, kept so the settings screen can name
 * the starting point and offer "reset to sector defaults". The module switches
 * themselves live in the `modules` json — this column is never read to decide
 * whether a screen is on. Nullable because every business that signed up
 * before the question existed never answered it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('sector', 40)->nullable()->after('industry');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('sector');
        });
    }
};
