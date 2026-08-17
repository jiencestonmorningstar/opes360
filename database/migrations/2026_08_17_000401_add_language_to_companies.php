<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The company's configured language — the default a multilingual
     * template's language picker opens on (§44). Additive: every company
     * that predates this column reads 'en', which is what the app already
     * assumed everywhere it hard-coded English copy.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('language', 8)->default('en')->after('sector');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
