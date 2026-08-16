<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What a van is, over and above what every asset is.
         *
         * Keyed one-to-one on `fixed_asset_id` rather than standing on its own,
         * because a vehicle is not a different kind of thing from a generator —
         * it is bought, depreciated, moved between sites, handed to a custodian
         * and disposed of by exactly the same machinery. A `vehicles` table
         * would fork that: the van's cost would live on the asset and its plate
         * on the vehicle, with nothing forcing them to describe the same van,
         * and every asset feature written since would have to be written twice.
         *
         * Only the columns a generator has no business carrying live here.
         */
        Schema::create('vehicle_details', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('fixed_asset_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('registration')->nullable();   // the plate
            $table->string('vin')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();

            $table->string('fuel_type')->nullable();      // petrol|diesel|electric|hybrid|lpg
            $table->decimal('tank_litres', 8, 2)->nullable();

            /*
             * Papers that stop the van legally. Three dates rather than one,
             * because they run on different clocks and lapse separately — a
             * business needs to know which one it is chasing this week.
             */
            $table->date('insurance_expires_on')->nullable();
            $table->date('roadworthy_expires_on')->nullable();
            $table->date('licence_expires_on')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            // One plate, one vehicle. Nullable, so vehicles entered before the
            // paperwork turns up do not collide with each other.
            $table->unique(['company_id', 'registration'], 'vehicle_details_company_plate_unique');
        });

        /*
         * A journey: two readings, a driver and a reason.
         *
         * There is no `distance` column. It is `end - start` and storing it as
         * well would let the three disagree after a correction, which is the
         * same reason maintenance does not keep its own copy of a cost.
         */
        Schema::create('vehicle_trips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('fixed_asset_id')->constrained()->cascadeOnDelete();

            // Who drove it on the day, which is not always who holds it. The
            // custodian on the asset answers "whose van is it"; this answers
            // "who was driving it when that happened".
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('trip_date');
            $table->unsignedInteger('start_odometer');
            $table->unsignedInteger('end_odometer');
            $table->string('purpose')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id'], 'vehicle_trips_company_asset_idx');
            $table->index(['company_id', 'trip_date'], 'vehicle_trips_company_date_idx');
        });

        /*
         * A fill: litres in, and the reading on the clock at the pump.
         *
         * Deliberately no amount. If the fuel was paid for there is already an
         * expense for it and this points at that expense — the same rule the
         * maintenance record follows, and for the same reason: two copies of a
         * figure make the month's fuel bill depend on which screen you ask.
         */
        Schema::create('fuel_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('filled_on');
            $table->decimal('litres', 10, 2);

            // Nullable because pump attendants forget, and a fill with no
            // reading is still a fill. It simply cannot be used for consumption.
            $table->unsignedInteger('odometer')->nullable();

            // Whether the tank was filled to the brim. Consumption measured
            // tank to tank only means anything between two full tanks.
            $table->boolean('is_full_tank')->default(true);

            $table->foreignUlid('expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignUlid('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id'], 'fuel_logs_company_asset_idx');
            $table->index(['company_id', 'filled_on'], 'fuel_logs_company_filled_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_logs');
        Schema::dropIfExists('vehicle_trips');
        Schema::dropIfExists('vehicle_details');
    }
};
