<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            /*
             * Every column here is nullable, without exception.
             *
             * This table already holds documents composed from templates long
             * before any of this existed, and they have to keep working exactly
             * as they do — six Papers test files say so. A NOT NULL column with
             * a default would technically migrate, but it would also assert a
             * classification for rows nobody ever classified, and a document
             * silently labelled "internal" when its author never said so is a
             * worse answer than one labelled nothing.
             */
            $table->string('kind')->nullable()->after('template');
            $table->text('description')->nullable()->after('title');
            $table->string('security')->nullable()->after('status');
            $table->string('language', 8)->nullable()->after('security');
            $table->json('tags')->nullable()->after('language');

            // Distinct from created_by: ownership transfers when somebody
            // leaves, authorship does not.
            $table->foreignId('owner_id')->nullable()->after('created_by')
                ->constrained('users')->nullOnDelete();

            $table->date('expires_on')->nullable()->after('issued_at');

            $table->index(['company_id', 'kind']);
            $table->index(['company_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'kind']);
            $table->dropIndex(['company_id', 'expires_on']);
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['kind', 'description', 'security', 'language', 'tags', 'expires_on']);
        });
    }
};
