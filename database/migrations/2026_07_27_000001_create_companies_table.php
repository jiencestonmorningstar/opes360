<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('motto')->nullable();
            $table->text('description')->nullable();
            $table->string('industry')->nullable();

            $table->string('registration_number')->nullable();
            // Encrypted at the model layer; blind indexes carry the lookups.
            $table->text('tax_id')->nullable();
            $table->string('tax_id_index')->nullable()->index();
            $table->text('vat_number')->nullable();

            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('postal_code')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('website')->nullable();
            $table->string('email')->nullable();
            $table->json('phones')->nullable();
            $table->json('socials')->nullable();
            $table->json('operating_hours')->nullable();

            $table->string('logo_path')->nullable();
            $table->string('banner_path')->nullable();
            // Phase 2 brand kit. Denormalised on purpose: read constantly by every
            // template, written rarely.
            $table->json('brand_tokens')->nullable();

            $table->string('currency', 3)->default('USD');
            $table->string('timezone')->default('UTC');
            $table->string('status')->default('active');

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
