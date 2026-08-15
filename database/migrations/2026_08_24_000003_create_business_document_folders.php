<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_document_folders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // Self-referential, nullOnDelete rather than cascade: deleting a
            // folder promotes its children rather than destroying a whole
            // branch. A mis-clicked delete that takes a subtree with it is not
            // recoverable from the interface.
            $table->foreignUlid('parent_id')->nullable()
                ->constrained('business_document_folders')->nullOnDelete();

            $table->string('name');

            // 'company' is shared; 'personal' belongs to one user and is hidden
            // from everybody else by the policy.
            $table->string('kind', 20)->default('company');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'parent_id']);
            $table->index(['company_id', 'kind']);
        });

        Schema::table('business_documents', function (Blueprint $table) {
            /*
             * nullOnDelete, and this is the important half of the folder story:
             * a folder is an arrangement, not a container. Deleting one must
             * never take documents with it — they fall back to the root, where
             * they can be found and re-filed. Losing a signed contract because
             * somebody tidied a folder tree is unrecoverable.
             */
            $table->foreignUlid('folder_id')->nullable()->after('kind')
                ->constrained('business_document_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('business_document_folders');
    }
};
