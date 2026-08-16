<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // A child outlives its parent and falls to the root. Deleting
            // "Operations" must not silently take Logistics with it.
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('departments')->nullOnDelete();

            $table->string('name');
            $table->string('code')->nullable();
            $table->text('description')->nullable();

            $table->foreignId('manager_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // 26-byte ULID + a varchar(255) name = 1046 bytes under utf8mb4,
            // well inside MySQL's 3072-byte index limit. Confirmed by
            // `php artisan opes:export-schema`, which builds a real MySQL
            // database — SQLite has accepted an over-long key before.
            $table->unique(['company_id', 'name']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
