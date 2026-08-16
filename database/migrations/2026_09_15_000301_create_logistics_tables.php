<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One consignment: somebody's cargo, from here to there.
         *
         * Sender and receiver are Contacts — the customer book the business
         * already keeps — not name columns of their own. The freight charge
         * invoices through the ordinary Document path and this table holds
         * only the link (`document_id`), never a copy of the money: the same
         * rule service jobs follow, and for the same reason — a figure stated
         * twice is a figure that can disagree with itself.
         */
        Schema::create('shipments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference');

            $table->foreignUlid('sender_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUlid('receiver_id')->constrained('contacts')->restrictOnDelete();

            $table->string('cargo_description');
            $table->decimal('weight_kg', 10, 2)->nullable();
            $table->decimal('declared_value', 14, 2)->nullable();

            $table->string('from_location');
            $table->string('to_location');

            // booked → loaded → in_transit → delivered, or cancelled. The
            // service sets it explicitly on create — a column default never
            // reaches the in-memory model create() hands back.
            $table->string('status');

            /*
             * The public door. Globally unique, resolved without a tenant the
             * way share and verification tokens already are: the token names
             * the shipment, the shipment names the company, and the tracking
             * page trusts nothing else.
             */
            $table->string('tracking_token', 64)->unique();

            $table->decimal('freight_amount', 14, 2)->nullable();
            $table->foreignUlid('document_id')->nullable()->constrained('documents')->nullOnDelete();

            /*
             * Proof of delivery: the paper the receiver signed, through the
             * existing e-signature flow. A link to a BusinessDocument, not a
             * second signature machine.
             */
            $table->foreignUlid('pod_document_id')->nullable()->constrained('business_documents')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'shipments_company_reference_unique');
            $table->index(['company_id', 'status'], 'shipments_company_status_idx');
        });

        /*
         * What the tracking page shows: the shipment's own history, one row
         * per turn of its status, in the words the business chose. Nothing
         * else ever renders there — no manifest, no other cargo, no names
         * beyond the shipment's own parties.
         */
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('shipment_id')->constrained('shipments')->cascadeOnDelete();

            $table->string('status');
            $table->string('note')->nullable();
            $table->timestamp('happened_at');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'shipment_id'], 'shipment_events_company_shipment_idx');
        });

        /*
         * One vehicle, one driver, one departure — and the shipments aboard.
         *
         * The vehicle is a FixedAsset. Not a `vehicles` table of its own:
         * the fleet migration already settled that a van is an asset with a
         * VehicleDetail hung off it, and a manifest that named vehicles any
         * other way would fork the register.
         */
        Schema::create('trip_manifests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference');

            $table->foreignUlid('fixed_asset_id')->constrained('fixed_assets')->restrictOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->date('departs_on');

            // open → dispatched → closed. Set explicitly by the service.
            $table->string('status');

            /*
             * The journey this manifest turned into, written when the manifest
             * closes with two odometer readings in hand. A link into the fleet
             * log, so the van's mileage history and the dispatch history are
             * the same history.
             */
            $table->foreignUlid('vehicle_trip_id')->nullable()->constrained('vehicle_trips')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'reference'], 'trip_manifests_company_reference_unique');
            $table->index(['company_id', 'status'], 'trip_manifests_company_status_idx');
        });

        /*
         * Which shipments are aboard which manifest.
         *
         * The business rule — a shipment may be aboard at most ONE *open*
         * manifest — is not expressible as a plain unique index, because
         * "open" lives on the parent row. The service enforces it inside a
         * transaction; the unique pair below is the floor underneath: the
         * same shipment can at least never be loaded twice onto the same
         * manifest, whatever races.
         *
         * Index names spelled out: the default names for this table run past
         * MySQL's 64-character identifier limit.
         */
        Schema::create('trip_manifest_shipments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('trip_manifest_id')->constrained('trip_manifests')->cascadeOnDelete();
            $table->foreignUlid('shipment_id')->constrained('shipments')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['trip_manifest_id', 'shipment_id'], 'tms_manifest_shipment_unique');
            $table->index(['company_id', 'shipment_id'], 'tms_company_shipment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_manifest_shipments');
        Schema::dropIfExists('trip_manifests');
        Schema::dropIfExists('shipment_events');
        Schema::dropIfExists('shipments');
    }
};
