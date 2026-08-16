<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_document_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('business_document_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            // A snapshot of the content columns only — never the filing
            // columns, which are not what a version is a version of.
            $table->string('title');
            $table->string('recipient')->nullable();
            $table->json('fields')->nullable();
            $table->longText('body')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Auto-generated name would be 71 characters — over MySQL's
            // 64-character identifier limit. SQLite accepted it silently,
            // which is exactly the class of bug docs/HANDOVER.md warns about.
            $table->unique(['business_document_id', 'version_number'], 'doc_version_unique');
            $table->index(['company_id', 'business_document_id']);
        });

        Schema::table('business_documents', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });

        Schema::dropIfExists('business_document_versions');
    }
};
