<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_document_relations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignUlid('business_document_id')
                ->constrained('business_documents')->cascadeOnDelete();

            /*
             * Polymorphic and deliberately unconstrained.
             *
             * A document attaches to a contact, an invoice, an employee or a
             * purchase order without this module knowing anything about any of
             * them, and without copying a single ERP row. That is the whole
             * architectural point: the authoritative record stays where it
             * lives.
             *
             * No foreign key here, and that is not an oversight — the target
             * is a different table on every row, so the database cannot express
             * it. The consequence is handled deliberately: deleting an ERP
             * record leaves its documents standing. A signed contract outlives
             * the customer row it was filed against, and cascading would
             * destroy the business's own paperwork as a side effect of tidying
             * a contact list.
             */
            /*
             * Lengths are explicit because these four columns share a unique
             * key, and MySQL caps an index at 3072 bytes. At Laravel's default
             * varchar(255) under utf8mb4 the key sums to 3164 and the table
             * simply cannot be created — which SQLite accepts silently, so the
             * test suite passed while production would have failed on deploy.
             *
             * 120 holds the longest model class name several times over, and an
             * id is a ULID at 26 characters.
             */
            $table->string('related_type', 120);
            $table->string('related_id', 40);

            // What the link means, so a document attached to an invoice can be
            // the thing it supports rather than merely "related".
            $table->string('role', 40)->default('about');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Attaching the same record twice in the same role is one link, not
            // two. The service leans on this rather than checking first.
            $table->unique(
                ['business_document_id', 'related_type', 'related_id', 'role'],
                'bdr_document_related_role_unique',
            );

            // The query this table exists to answer: "what documents belong to
            // this customer?"
            $table->index(['company_id', 'related_type', 'related_id'], 'bdr_company_related_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_relations');
    }
};
