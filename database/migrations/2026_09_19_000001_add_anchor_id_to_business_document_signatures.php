<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §19's last remnant: a signature block at a specific position in the text,
 * rather than a round attached to the document as a whole.
 *
 * `anchor_id` matches the same `data-block-id` the editor already stamps on
 * every top-level block for comment anchoring — see
 * resources/js/editor/block-anchor.js. Reusing that id rather than inventing a
 * second positioning scheme means a signature and a comment point at the same
 * block in the same way, and the editor needed no new work at all.
 *
 * Nullable, and that is the ordinary case: a signature with no anchor signs
 * the document as a whole, exactly as every existing signature already does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_document_signatures', function (Blueprint $table) {
            $table->string('anchor_id')->nullable()->after('business_document_id');
            $table->index(['business_document_id', 'anchor_id'], 'bds_document_anchor_index');
        });
    }

    public function down(): void
    {
        Schema::table('business_document_signatures', function (Blueprint $table) {
            $table->dropIndex('bds_document_anchor_index');
            $table->dropColumn('anchor_id');
        });
    }
};
