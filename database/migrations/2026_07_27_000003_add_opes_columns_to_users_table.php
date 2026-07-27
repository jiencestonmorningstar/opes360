<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('email');
            $table->string('phone')->nullable()->after('avatar_path');
            // Nullable + nullOnDelete: a user whose last company is deleted must
            // still be able to sign in and create another.
            $table->foreignUlid('current_company_id')->nullable()->after('phone')
                ->constrained('companies')->nullOnDelete();
            $table->string('theme')->default('system')->after('current_company_id');
            $table->string('locale', 5)->default('en')->after('theme');
            $table->timestamp('last_seen_at')->nullable()->after('locale');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_company_id');
            $table->dropColumn([
                'avatar_path', 'phone', 'theme', 'locale', 'last_seen_at', 'deleted_at',
            ]);
        });
    }
};
