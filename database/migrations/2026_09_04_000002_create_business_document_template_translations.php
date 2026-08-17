<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-language body variants for a business's own templates (§44).
     *
     * A separate table rather than a JSON column on business_document_templates
     * itself: `body` there is a plain longtext column holding the single
     * default-language body, and every existing reader (CustomDocumentTemplates,
     * DocumentComposer, the template versions table) already treats it as one
     * string. Cramming a language map into that column would mean every one of
     * those callers has to learn the new shape just to keep working, whereas a
     * translations table is purely additive: a template with zero rows here
     * behaves exactly as it always has, body column untouched.
     */
    public function up(): void
    {
        Schema::create('business_document_template_translations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->ulid('business_document_template_id');
            $table->foreign('business_document_template_id', 'doc_template_translation_template_fk')
                ->references('id')->on('business_document_templates')->cascadeOnDelete();

            // ISO language code — same width as business_documents.language,
            // so a value copied from one column to the other never truncates.
            $table->string('language', 8);
            $table->longText('body');

            $table->timestamps();

            $table->unique(
                ['business_document_template_id', 'language'],
                'doc_template_translation_unique',
            );
            $table->index(
                ['company_id', 'business_document_template_id'],
                'doc_template_translations_company_template_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_template_translations');
    }
};
