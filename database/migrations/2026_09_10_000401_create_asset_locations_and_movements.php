<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Where the business keeps things. Named `asset_locations` rather
         * than `locations` because `stock_locations` already exists and means
         * something different: a warehouse holds stock that is sold, a site
         * holds equipment that is owned. A business often has both at one
         * address, and merging them would force a shelf and a generator into
         * the same list.
         */
        Schema::create('asset_locations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('code')->nullable();
            $table->text('address')->nullable();
            $table->foreignUlid('department_id')->nullable()
                ->constrained('departments')->nullOnDelete();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name']);
        });

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreignUlid('asset_location_id')->nullable()->after('location')
                ->constrained('asset_locations')->nullOnDelete();

            // Who currently holds it. A laptop is at Head Office and with
            // Aïcha; the two answers are different and a business chasing a
            // missing asset needs the second one.
            $table->foreignId('custodian_id')->nullable()->after('asset_location_id')
                ->constrained('users')->nullOnDelete();

            $table->index(['company_id', 'asset_location_id'], 'fixed_assets_company_location_idx');
        });

        $this->backfillLocations();

        /*
         * Every move, kept. "Where is the generator" is answered by the
         * asset's current location; "where has it been, and who signed for
         * it" is answered here, and that is the question asked when
         * something goes missing — which is when it matters.
         */
        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('fixed_asset_id')->constrained()->cascadeOnDelete();

            // Both nullable: an asset can move location without changing
            // hands, or change hands without moving.
            $table->foreignUlid('from_location_id')->nullable()
                ->constrained('asset_locations')->nullOnDelete();
            $table->foreignUlid('to_location_id')->nullable()
                ->constrained('asset_locations')->nullOnDelete();
            $table->foreignId('from_custodian_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('to_custodian_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->date('transferred_on');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'fixed_asset_id'], 'asset_transfers_company_asset_idx');
        });

        /*
         * Servicing — both what is due and what was done. One table rather
         * than a schedule and a log, because a completed service and an
         * upcoming one are the same row at different times, and splitting
         * them means reconciling two records of the same event.
         */
        Schema::create('asset_maintenance', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('fixed_asset_id')->constrained()->cascadeOnDelete();

            $table->string('kind')->default('service');   // service|repair|inspection
            $table->string('title');
            $table->text('notes')->nullable();

            $table->date('due_on')->nullable();
            $table->date('completed_on')->nullable();

            // Servicing that repeats. Set on the row rather than in a separate
            // schedule table, so completing one visit can raise the next from
            // what the business actually did rather than from a plan that has
            // drifted away from it.
            $table->unsignedSmallInteger('interval_months')->nullable();
            $table->decimal('cost', 14, 2)->nullable();

            // The bill, if the work was paid for. Points at the existing
            // expense rather than recording a second copy of the amount.
            $table->foreignUlid('expense_id')->nullable()
                ->constrained('expenses')->nullOnDelete();

            $table->foreignUlid('supplier_id')->nullable()
                ->constrained('contacts')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'due_on'], 'asset_maintenance_company_due_idx');
            $table->index(['company_id', 'fixed_asset_id'], 'asset_maintenance_company_asset_idx');
        });
    }

    /**
     * Every distinct location already typed against an asset becomes a real
     * location, and that asset is linked to it.
     *
     * The free-text column stays exactly as it was — it is what the business
     * typed, and if this guesses wrong about two spellings being one site
     * they must be able to see that and fix it. Identical reasoning to the
     * departments backfill; see 2026_08_25_000002.
     */
    protected function backfillLocations(): void
    {
        $rows = DB::table('fixed_assets')
            ->select('company_id', 'location')
            ->whereNotNull('location')
            ->where('location', '!=', '')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            $id = (string) Str::ulid();

            DB::table('asset_locations')->insert([
                'id' => $id,
                'company_id' => $row->company_id,
                'name' => $row->location,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('fixed_assets')
                ->where('company_id', $row->company_id)
                ->where('location', $row->location)
                ->update(['asset_location_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance');
        Schema::dropIfExists('asset_transfers');

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custodian_id');
            $table->dropConstrainedForeignId('asset_location_id');
        });

        Schema::dropIfExists('asset_locations');
    }
};
