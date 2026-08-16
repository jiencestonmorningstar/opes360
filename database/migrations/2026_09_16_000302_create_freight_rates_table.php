<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A rate card: what this business charges to carry a kilogram from
         * here to there, and the least it will take the job for.
         *
         * The card PROPOSES; the person at the counter decides. Booking reads
         * the card to prefill the freight figure and the clerk may overtype
         * it — freight in this market is negotiated cargo by cargo, and a
         * table that dictated the price would just be routed around with a
         * fake route name. So nothing here is a constraint; it is the number
         * the argument starts from.
         */
        Schema::create('freight_rates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('from_location');
            $table->string('to_location');

            $table->decimal('per_kg', 10, 2);
            $table->decimal('minimum', 14, 2);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One card per route per company. Name spelled out to stay under
            // MySQL's 64-character identifier limit.
            $table->unique(['company_id', 'from_location', 'to_location'], 'freight_rates_company_route_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freight_rates');
    }
};
