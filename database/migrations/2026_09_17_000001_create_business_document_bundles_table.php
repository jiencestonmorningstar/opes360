<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §60 — background bundling.
     *
     * A ZIP of a large selection takes long enough that holding the request
     * open for it is the wrong shape once a real queue worker exists. This
     * table is the receipt: one row per requested bundle, so the requester
     * can be told when it is ready instead of staring at a spinner.
     *
     * Under the `sync` queue connection nothing ever reads this table — the
     * download still happens inline, in the same request, exactly as it
     * always has (see App\Services\Documents\DocumentBundles::request()).
     * It exists only for the case where a worker really is running.
     */
    public function up(): void
    {
        Schema::create('business_document_bundles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('pending'); // pending, ready, failed
            $table->unsignedInteger('document_count')->default(0);
            $table->string('disk')->nullable();
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('ready_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_document_bundles');
    }
};
