<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_document_comments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('business_document_id')->constrained()->cascadeOnDelete();

            // A reply outlives nothing being deleted here — comments are never
            // bulk-removed by cascading a parent, only the document itself
            // takes its comments with it.
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('business_document_comments')->cascadeOnDelete();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');

            // Explicit ids rather than parsing @name out of the body — a
            // parser guesses who was meant and is wrong the day two people
            // share a name; the composer already knows who it is offering.
            $table->json('mentioned_user_ids')->nullable();

            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'business_document_id']);
            $table->index(['business_document_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_comments');
    }
};
