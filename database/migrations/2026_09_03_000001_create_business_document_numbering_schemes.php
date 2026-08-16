<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Per-kind numbering, per business. §25: "not every document
         * requires a number," and where one is wanted the prefix is
         * configurable — CONTRACT-2026-000001 rather than the flat
         * DOC-2026-000001 every kind gets today.
         *
         * A business that never visits this table changes nothing: every
         * kind keeps using the existing 'business_document'/'DOC' series in
         * DocumentNumbers, untouched. This table only takes effect for a
         * company and kind that explicitly has a row.
         */
        Schema::create('business_document_numbering_schemes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Null kind is the catch-all for anything no more specific row covers.
            $table->string('kind')->nullable();
            $table->string('prefix', 20);
            $table->boolean('requires_number')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'kind'], 'doc_numbering_scheme_company_kind_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_numbering_schemes');
    }
};
