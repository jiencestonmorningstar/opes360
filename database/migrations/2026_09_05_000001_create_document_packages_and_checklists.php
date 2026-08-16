<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * §38 — a named group of documents: "Annual Audit Package 2026".
         * A package holds references, never copies: removing a document from
         * a package leaves the document untouched, and deleting a package
         * deletes none of what it grouped.
         */
        Schema::create('business_document_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('open');   // open|closed

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        /*
         * A plain pivot: no surrogate id and no company_id of its own.
         * Eloquent's belongsToMany does not populate either, and both would
         * be redundant anyway — a row is identified by the pair it joins,
         * and its tenancy is whatever the package on one side already has.
         */
        Schema::create('business_document_package_items', function (Blueprint $table) {
            $table->ulid('business_document_package_id');
            $table->foreignUlid('business_document_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Named explicitly: the auto-generated FK and unique names run
            // past MySQL's 64-character identifier limit, which SQLite
            // accepts silently.
            $table->foreign('business_document_package_id', 'doc_package_item_package_fk')
                ->references('id')->on('business_document_packages')->cascadeOnDelete();
            $table->unique(
                ['business_document_package_id', 'business_document_id'],
                'doc_package_item_unique',
            );
        });

        /*
         * §40 — what a business requires before a record is complete:
         * "a supplier needs a registration document, a tax certificate, a
         * signed agreement." The checklist names document *kinds*; whether
         * each is satisfied is answered by looking at what is actually
         * linked to the record, never by a second stored flag that could
         * drift from the documents themselves.
         */
        Schema::create('business_document_checklists', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            // The ERP record type this checklist applies to, as a morph alias
            // (App\Models\Contact, App\Models\Employee…).
            $table->string('subject_type');
            $table->json('required_kinds');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'subject_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_checklists');
        Schema::dropIfExists('business_document_package_items');
        Schema::dropIfExists('business_document_packages');
    }
};
