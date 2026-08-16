<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * How long a kind of document must be kept, per business. §31: "Do
         * not hard-code legal retention periods" — a policy is data a
         * business sets for itself, never a constant compiled into the
         * product. A null kind is the catch-all: whatever no more specific
         * row covers.
         */
        Schema::create('business_document_retention_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('kind')->nullable();
            $table->unsignedInteger('retain_years');
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['company_id', 'kind'], 'doc_retention_policy_company_kind_unique');
        });

        Schema::table('business_documents', function (Blueprint $table) {
            // Blocks disposal outright while set, regardless of what any
            // retention policy would otherwise allow — a hold overrides a
            // schedule, never the other way round.
            $table->boolean('legal_hold')->default(false)->after('signature_mode');
            $table->text('legal_hold_reason')->nullable()->after('legal_hold');
            $table->foreignId('legal_hold_set_by')->nullable()->after('legal_hold_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('legal_hold_set_at')->nullable()->after('legal_hold_set_by');

            $table->index(['company_id', 'legal_hold']);
        });
    }

    public function down(): void
    {
        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legal_hold_set_by');
            $table->dropColumn(['legal_hold', 'legal_hold_reason', 'legal_hold_set_at']);
        });

        Schema::dropIfExists('business_document_retention_policies');
    }
};
