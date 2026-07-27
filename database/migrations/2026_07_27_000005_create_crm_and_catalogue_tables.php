<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('customer'); // customer|supplier|vendor|lead
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->json('phones')->nullable();
            $table->string('whatsapp')->nullable();
            $table->json('address')->nullable();
            $table->text('tax_id')->nullable();
            $table->string('tax_id_index')->nullable();
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            // Cached rollup of unpaid document balances; recomputed on write.
            $table->decimal('balance', 15, 2)->default(0);
            $table->string('avatar_path')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'type']);
            $table->index(['company_id', 'tax_id_index']);
            $table->index(['company_id', 'name']);
        });

        Schema::create('contact_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('note');
            $table->text('body');
            $table->timestamps();

            $table->index(['contact_id', 'created_at']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'slug']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('rate', 7, 4);
            $table->boolean('is_compound')->default(false);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['company_id', 'is_default']);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('product'); // product|service
            $table->foreignUlid('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit')->default('unit');
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('cost', 15, 2)->nullable();
            $table->foreignUlid('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->boolean('track_stock')->default(false);
            $table->decimal('reorder_level', 15, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('image_path')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('device_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'type', 'is_active']);
            $table->index(['company_id', 'barcode']);
        });

        /*
         * Append-only, signed movement ledger — never an absolute quantity write.
         * Two devices selling the same item offline each append -1 and both are
         * correct; a mutable `quantity` column would have them overwrite each
         * other. Current stock is the sum. See docs/architecture/offline-sync.md.
         */
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('item_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('reason'); // sale|purchase|adjustment|return|opening
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('device_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'item_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('items');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('contact_notes');
        Schema::dropIfExists('contacts');
    }
};
