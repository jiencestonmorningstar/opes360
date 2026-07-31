<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The balance lives on the contact for a cheap read on the customer page;
     * loyalty_transactions is the source of truth it is always derived from.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedInteger('loyalty_points')->default(0)->after('balance');
            $table->string('loyalty_card_number')->nullable()->after('loyalty_points');
            $table->timestamp('loyalty_card_issued_at')->nullable()->after('loyalty_card_number');
            $table->foreignUlid('loyalty_verification_token_id')->nullable()->after('loyalty_card_issued_at')
                ->constrained('verification_tokens')->nullOnDelete();

            $table->unique(['company_id', 'loyalty_card_number']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loyalty_verification_token_id');
            $table->dropColumn(['loyalty_points', 'loyalty_card_number', 'loyalty_card_issued_at']);
        });
    }
};
