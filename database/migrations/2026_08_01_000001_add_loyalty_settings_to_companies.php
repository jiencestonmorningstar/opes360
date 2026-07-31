<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A company's loyalty program, off by default. Two numbers define the
     * whole scheme: how much spend earns one point, and what one point is
     * worth back — everything else (issuing cards, redeeming) reads these.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('loyalty_enabled')->default(false)->after('demo_expires_at');
            // 1 point per this many currency units spent, e.g. 100 → $1 = 1pt.
            $table->decimal('loyalty_points_per_amount', 12, 2)->default(100)->after('loyalty_enabled');
            // What one point is worth when redeemed, in the company's currency.
            $table->decimal('loyalty_point_value', 12, 4)->default(1)->after('loyalty_points_per_amount');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['loyalty_enabled', 'loyalty_points_per_amount', 'loyalty_point_value']);
        });
    }
};
