<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep the upload as it arrived.
     *
     * Uploads are now cleaned automatically — a uniform background made
     * transparent, the border trimmed, the size bounded. That is right almost
     * always and wrong occasionally, and when it is wrong the business should
     * not be asked to go and find the file again. The untouched original is
     * kept beside the cleaned one so it can simply be handed back.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('logo_original_path')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('logo_original_path');
        });
    }
};
