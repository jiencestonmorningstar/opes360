<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8.3 of the master spec: a comment anchored to a specific block inside the
 * document, not just to the document as a whole. `anchor_id` matches a
 * FieldToken-style stable id the editor stamps on each top-level block
 * (paragraph, heading, list item, table row) — see
 * resources/js/editor/block-anchor.js. Nullable: an unanchored comment is
 * still a document-level remark, exactly as every existing comment already
 * behaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_document_comments', function (Blueprint $table) {
            $table->string('anchor_id')->nullable()->after('business_document_id');
            $table->index(['business_document_id', 'anchor_id']);
        });
    }

    public function down(): void
    {
        Schema::table('business_document_comments', function (Blueprint $table) {
            $table->dropIndex(['business_document_id', 'anchor_id']);
            $table->dropColumn('anchor_id');
        });
    }
};
