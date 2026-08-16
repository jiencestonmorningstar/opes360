<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A business's own templates, alongside the built-in catalogue in
         * App\Support\DocumentTemplates — which stays exactly what it is: a
         * curated set every business starts with, in code because it ships
         * with the product and is reviewed like any other code. This table
         * is what a business writes for itself. The two are merged for
         * display and compose from the same catalogue contract (name,
         * summary, icon, accent, binding, fields, body) so neither the
         * gallery nor DocumentComposer has to know which kind it is holding.
         */
        Schema::create('business_document_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // The slug used in the compose URL and stored on every document
            // made from it — never reused once anything has been generated
            // from it, so a key is never deleted, only unpublished.
            $table->string('key');

            $table->string('name');
            $table->text('summary')->nullable();
            $table->string('icon')->default('document');
            $table->string('accent')->default('blue');
            $table->boolean('binding')->default(false);
            $table->json('fields');
            $table->longText('body');

            // Unpublished by default: a template being drafted must not
            // appear in the gallery before it is ready.
            $table->boolean('is_published')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'key']);
            $table->index(['company_id', 'is_published']);
        });

        Schema::create('business_document_template_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Named explicitly — the auto-generated FK name runs to 76
            // characters, over MySQL's 64-character identifier limit.
            // SQLite accepted it silently, the same class of bug this
            // module has hit repeatedly this session; see
            // opes:export-schema's role in docs/HANDOVER.md.
            $table->ulid('business_document_template_id');
            $table->foreign('business_document_template_id', 'doc_template_version_template_fk')
                ->references('id')->on('business_document_templates')->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            $table->string('name');
            $table->text('summary')->nullable();
            $table->json('fields');
            $table->longText('body');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['business_document_template_id', 'version_number'],
                'doc_template_version_unique',
            );
            $table->index(
                ['company_id', 'business_document_template_id'],
                'doc_template_versions_company_template_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_template_versions');
        Schema::dropIfExists('business_document_templates');
    }
};
