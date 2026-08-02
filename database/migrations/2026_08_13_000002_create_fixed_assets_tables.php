<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the business owns.
     *
     * ── Why an asset is not an expense ───────────────────────────────────
     *
     * Until now a delivery van bought for 8 000 000 F went through the expense
     * screen and took the whole 8 000 000 out of one month's profit. That is
     * wrong twice over: the month looks catastrophic, and every month
     * afterwards looks better than it is, because the van goes on being used
     * and never costs anything again. The DGI takes the same view — the
     * purchase is not deductible, the annual depreciation is.
     *
     * So an asset is capitalised into class 2 when it is bought, and its cost
     * is spread across the years it will actually be used.
     *
     * ── Why the schedule is stored ───────────────────────────────────────
     *
     * `depreciation_entries` holds one row per period charged, rather than the
     * charge being computed from the acquisition date on demand. Two reasons:
     * a business that starts using this software mid-life needs to record what
     * has already been depreciated elsewhere, and the charge that was posted
     * to March has to stay what it was even after somebody corrects the useful
     * life in June.
     */
    public function up(): void
    {
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference')->nullable();      // our own tag/plate
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');                   // see AssetCategories

            // The class-2 account it sits in, and the class-28 account its
            // accumulated depreciation is credited to. Held as ids so
            // recategorising the list later cannot rewrite what a past year
            // was posted against.
            $table->foreignUlid('ledger_account_id')->nullable()
                ->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignUlid('depreciation_account_id')->nullable()
                ->constrained('ledger_accounts')->nullOnDelete();

            $table->foreignUlid('supplier_id')->nullable()->constrained('contacts')->nullOnDelete();
            // Set when the asset was capitalised straight from a recorded
            // purchase, so the two cannot both charge the books.
            $table->foreignUlid('expense_id')->nullable()->constrained('expenses')->nullOnDelete();

            $table->date('acquired_on');
            $table->date('in_service_on')->nullable();    // depreciation starts here
            $table->decimal('cost', 16, 2);               // hors taxes
            $table->decimal('residual_value', 16, 2)->default(0);
            $table->string('currency', 3)->default('XAF');

            $table->string('method')->default('straight_line'); // straight_line|declining
            $table->unsignedSmallInteger('useful_life_months')->default(60);
            $table->decimal('declining_rate', 6, 4)->nullable();

            /*
             * What was already written off before this software knew about the
             * asset. A business four years into a van's life has to be able to
             * say so, or its balance sheet is wrong from the day it starts.
             */
            $table->decimal('opening_accumulated', 16, 2)->default(0);
            $table->decimal('accumulated_depreciation', 16, 2)->default(0);

            $table->string('status')->default('active');  // active|disposed|written_off
            $table->date('disposed_on')->nullable();
            $table->decimal('disposal_proceeds', 16, 2)->nullable();
            $table->string('disposal_note')->nullable();

            $table->string('location')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'acquired_on']);
            $table->index(['company_id', 'category']);
        });

        Schema::create('depreciation_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('fixed_asset_id')->constrained('fixed_assets')->cascadeOnDelete();

            // Always the first of the month charged. One charge per asset per
            // period, which the unique index enforces — running the month
            // twice must not depreciate the van twice.
            $table->date('period');
            $table->decimal('amount', 16, 2);
            $table->decimal('accumulated_after', 16, 2);
            $table->decimal('book_value_after', 16, 2);

            $table->string('status')->default('posted'); // posted|reversed
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period']);
            $table->index(['company_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depreciation_entries');
        Schema::dropIfExists('fixed_assets');
    }
};
