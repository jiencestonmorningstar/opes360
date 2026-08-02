<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The secretariat / print-shop partner programme.
     *
     * A secretariat is an ordinary company that also resells: it prints cards
     * and letterheads for businesses that have no account of their own, and
     * earns a recurring share of the subscription from any business it brings
     * onto the platform.
     *
     * `kind` is deliberately a separate column from `account_type`. That column
     * already carries the account's lifecycle — demo, trial, active — and
     * PlanEntitlements keys entitlements off it, so folding a second meaning
     * into the same string would silently strip a secretariat of every module
     * the moment it stopped reading 'active'. The two are orthogonal: a
     * secretariat can be on trial, and a plain business can be active.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('kind')->default('business')->after('account_type'); // business|secretariat

            // The referral code a secretariat hands out. Null for everyone else;
            // unique so an invite link can resolve to exactly one partner.
            $table->string('partner_code')->nullable()->unique()->after('kind');

            // Who brought this business in, captured once at registration and
            // never rewritten — the commission trail depends on it not moving.
            $table->foreignUlid('referred_by_company_id')->nullable()->after('partner_code')
                ->constrained('companies')->nullOnDelete();
            $table->timestamp('referred_at')->nullable()->after('referred_by_company_id');
        });

        /*
         * A business the secretariat prints for. It has no login and may never
         * have one: the whole point is that a print shop can serve the shop
         * next door without that shop signing up for anything.
         */
        Schema::create('partner_clients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('industry')->nullable();
            $table->string('city')->nullable();
            $table->text('notes')->nullable();

            // Personalised invite link. Distinct from the partner's own code so
            // a conversion can be traced to the client record, not just to the
            // partner — which is what makes "which of my clients signed up?"
            // answerable.
            $table->string('invite_token')->unique();

            $table->foreignUlid('converted_company_id')->nullable()
                ->constrained('companies')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'name']);
        });

        /*
         * One row per card or letterhead the partner generates. This is the
         * billing record, so it is written when the artwork is produced and
         * never edited afterwards — a correction is a void, not an update.
         */
        Schema::create('card_issuances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('partner_client_id')->nullable()
                ->constrained('partner_clients')->nullOnDelete();

            $table->string('asset');   // card|letterhead
            $table->string('design');
            $table->string('subject_name');

            // The fee is copied onto the row rather than read from config at
            // display time: a partner's statement for March must not change
            // because the price changed in April.
            $table->unsignedInteger('fee');
            $table->string('currency', 3)->default('XAF');
            $table->string('status')->default('billed'); // billed|void
            $table->text('void_reason')->nullable();

            $table->foreignUlid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'status']);
        });

        /*
         * Commission earned on a referred business's subscription payment.
         *
         * subscription_payment_id is unique: a webhook and a manual status
         * check can both settle the same payment, and crediting a partner twice
         * for one payment is the failure mode that matters here.
         */
        Schema::create('partner_commissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('source_company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('subscription_payment_id')->unique()
                ->constrained('subscription_payments')->cascadeOnDelete();

            $table->unsignedInteger('amount');
            $table->decimal('rate', 5, 4);
            $table->unsignedInteger('base_amount');
            $table->string('currency', 3)->default('XAF');
            $table->string('status')->default('earned'); // earned|reversed

            $table->timestamps();

            $table->index(['company_id', 'created_at']);
        });

        /*
         * A partner asking to be paid what they are owed. Settled by a platform
         * admin outside the app — mobile money, mostly — so this table records
         * the request and the decision rather than moving any money itself.
         */
        Schema::create('partner_payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->unsignedInteger('amount');
            $table->string('currency', 3)->default('XAF');
            $table->string('status')->default('requested'); // requested|paid|rejected
            $table->string('method')->nullable();           // mtn|orange|bank
            $table->string('destination')->nullable();
            $table->text('note')->nullable();

            $table->foreignUlid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_payouts');
        Schema::dropIfExists('partner_commissions');
        Schema::dropIfExists('card_issuances');
        Schema::dropIfExists('partner_clients');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_company_id');
            $table->dropColumn(['kind', 'partner_code', 'referred_at']);
        });
    }
};
