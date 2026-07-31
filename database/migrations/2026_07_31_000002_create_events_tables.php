<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opes Events — ticketed events a business publishes and sells (Module 17).
     *
     * Tickets are individual rows rather than an order with a quantity: each
     * one carries its own verification token, its own QR, its own check-in
     * state — a pair of tickets bought together still admits two people
     * through the door separately.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('venue')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->string('status')->default('draft'); // draft|published|cancelled
            // The public sales page URL. Random for the same reason as forms.
            $table->string('share_token', 32)->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'starts_at']);
        });

        Schema::create('ticket_types', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            // Null quantity means unlimited. `sold` is only ever moved inside
            // a row-locked transaction — it is the oversell guard.
            $table->unsignedInteger('quantity')->nullable();
            $table->unsignedInteger('sold')->default(0);
            $table->unsignedInteger('sort')->default(0);

            $table->timestamps();

            $table->index(['event_id', 'sort']);
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('ticket_type_id')->constrained()->cascadeOnDelete();

            $table->string('serial');
            $table->string('buyer_name');
            $table->string('buyer_email')->nullable();
            $table->string('buyer_phone')->nullable();
            // Price frozen at purchase; the type's price can change later.
            $table->decimal('price', 12, 2)->default(0);

            $table->string('status')->default('issued'); // issued|checked_in|void
            $table->timestamp('checked_in_at')->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            $table->foreignUlid('verification_token_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'serial']);
            $table->index(['event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('ticket_types');
        Schema::dropIfExists('events');
    }
};
