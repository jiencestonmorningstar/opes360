<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('artisans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Public URL, so it must be globally unique rather than per-company.
            $table->string('slug')->unique();
            $table->string('artisan_number')->nullable();

            $table->string('full_name');
            $table->string('photo_path')->nullable();
            $table->string('occupation')->nullable();
            $table->string('trade_category')->nullable();
            $table->json('skills')->nullable();
            $table->text('biography')->nullable();
            $table->unsignedSmallInteger('years_experience')->nullable();
            $table->json('certifications')->nullable();
            $table->json('languages')->nullable();
            $table->json('coverage_area')->nullable();

            $table->json('phones')->nullable();
            $table->string('whatsapp')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->json('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('working_hours')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->json('socials')->nullable();

            $table->foreignUlid('verification_token_id')->nullable()
                ->constrained('verification_tokens')->nullOnDelete();
            $table->boolean('is_verified')->default(false);
            $table->boolean('is_published')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'trade_category']);
            $table->index(['company_id', 'is_published']);
        });

        Schema::create('artisan_testimonials', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('artisan_id')->constrained()->cascadeOnDelete();
            $table->string('author_name');
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('body');
            $table->boolean('is_published')->default(true);
            $table->timestamps();

            $table->index(['artisan_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artisan_testimonials');
        Schema::dropIfExists('artisans');
    }
};
