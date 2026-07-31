<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company's account stage: demo -> trial -> active. Nothing in the
     * product behaves differently by stage today except the banner and the
     * expiry job — the columns exist so that gate can be added without a
     * second migration once pricing enforcement is built.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('account_type')->default('active')->after('timezone'); // demo|trial|active
            $table->timestamp('demo_expires_at')->nullable()->after('account_type');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['account_type', 'demo_expires_at']);
        });
    }
};
