<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which paid tier a company is on — basic|growth|business — and whether a
     * platform admin has suspended it. Independent of account_type: a demo or
     * trial gets full module access regardless of `plan` (see
     * PlanEntitlements), so the plan only bites once an account goes active.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('plan')->default('basic')->after('account_type');
            $table->timestamp('suspended_at')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['plan', 'suspended_at']);
        });
    }
};
