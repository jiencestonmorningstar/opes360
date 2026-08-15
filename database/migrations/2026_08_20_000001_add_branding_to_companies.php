<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            /*
             * Branding inputs only — the seed colours and the shape, density
             * and skin choices. The derived palette is never stored.
             *
             * That is deliberate: the derivation is the part most likely to
             * improve, and storing its output would strand every existing
             * company on whatever the generator produced the day they saved.
             * Recomputing from the inputs means one fix reaches everybody.
             *
             * Null means "platform default", so nothing changes for a company
             * until somebody actually opens the branding screen.
             */
            $table->json('branding')->nullable()->after('brand_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('branding');
        });
    }
};
