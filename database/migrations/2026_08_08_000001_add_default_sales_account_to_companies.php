<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which revenue account a sale posts to when the line itself does not say.
     *
     * The composer lets people type a line description freehand rather than
     * always picking a catalogue item, so most lines carry no item and nothing
     * to infer goods-or-services from. Until now every sale went to 701 Ventes
     * de marchandises, which books a consultancy's entire income as the sale
     * of goods it never sold.
     *
     * Defaulted to sales_goods because that is what the books already say for
     * existing data — changing the default would silently restate history. A
     * services business sets it once and its future sales land in 706.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('default_sales_account')->default('sales_goods')->after('prices_include_tax');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('default_sales_account');
        });
    }
};
