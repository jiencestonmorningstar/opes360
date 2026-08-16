<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Job titles as an entity, the way departments already are.
     *
     * A department is optional on a position. Businesses this size abolish and
     * merge departments constantly, and a "Driver" who belongs to nobody in
     * particular is a normal state, not a broken one — so the link nulls out
     * rather than cascading, and abolishing Finance never takes Accountant
     * with it.
     */
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignUlid('department_id')->nullable()
                ->constrained('departments')->nullOnDelete();

            $table->string('title');
            $table->string('code')->nullable();
            $table->text('description')->nullable();

            // Free text on purpose. Grade schemes here follow the convention
            // collective of the trade, not anything this software can enumerate.
            $table->string('grade')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // 26-byte ULID + varchar(255) title stays well inside MySQL's
            // 3072-byte key limit, as departments' identical pair already does.
            $table->unique(['company_id', 'title']);
            $table->index(['company_id', 'is_active']);
            $table->index(['company_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
