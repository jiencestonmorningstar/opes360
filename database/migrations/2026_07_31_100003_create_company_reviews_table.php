<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->string('author_name');
            $table->unsignedTinyInteger('rating');
            $table->text('body');
            // Public submissions start hidden; the business publishes them.
            $table->boolean('is_published')->default(false);
            // sha256 of the submitter's IP — enough to group abusive submissions
            // without retaining the address itself.
            $table->string('submitted_ip_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_published']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_reviews');
    }
};
