<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the current paid plan's billing period ends. Set by
     * SubscriptionBiller on a successful payment; left null for demo/trial
     * accounts and for plans a platform admin assigned by hand.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestamp('plan_renews_at')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('plan_renews_at');
        });
    }
};
