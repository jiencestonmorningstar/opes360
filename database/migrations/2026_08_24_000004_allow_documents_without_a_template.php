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
             * An uploaded file is not composed from a template.
             *
             * Until now every row here came from the compose screen, so the
             * column was rightly required. An uploaded PDF has no template at
             * all, and storing a sentinel value would be worse than null: it
             * would list the file in template filters and offer it to the
             * compose screen, which cannot open it.
             *
             * templateName() already returns 'Document' when the template does
             * not resolve, so nothing downstream needs changing.
             */
            $table->string('template')->nullable()->change();

            // Likewise the body: an upload's content is the file, not markup.
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->string('template')->nullable(false)->change();
            $table->text('body')->nullable(false)->change();
        });
    }
};
