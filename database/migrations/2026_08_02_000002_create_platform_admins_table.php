<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform staff — deliberately its own table, not a flag on `users`.
     * Being a business Owner never implies platform access, and a platform
     * admin is not automatically a member of any business; the two systems
     * share nothing but a login page.
     */
    public function up(): void
    {
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('platform_admin_activity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('platform_admin_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // e.g. 'suspended_company', 'changed_plan'
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admin_activity');
        Schema::dropIfExists('platform_admins');
    }
};
