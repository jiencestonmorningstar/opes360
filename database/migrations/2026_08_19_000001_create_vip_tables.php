<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VIP membership: a customer buys a tier and, for the term they bought,
     * their invoices carry the discount that tier promised.
     *
     * Two tables rather than one, for the same reason a price list and an
     * invoice are two different documents: `vip_tiers` is the price list —
     * what a business currently sells and for how much — and
     * `vip_memberships` is what an individual member actually bought.
     */
    public function up(): void
    {
        Schema::create('vip_tiers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->decimal('price', 14, 2)->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->unsignedSmallInteger('period_months')->default(12);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->text('perks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active']);
        });

        Schema::create('vip_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();

            $table->foreignUlid('vip_tier_id')->nullable()
                ->constrained('vip_tiers')->nullOnDelete();

            /*
             * `tier_name`, `discount_percent` and `price_paid` are copied from
             * the tier at the moment of sale rather than read through
             * `vip_tier_id`, on purpose. The tier is where FUTURE sales get
             * their terms; the membership is where a PAST sale's terms are
             * recorded. Raising Gold's discount next year must not silently
             * rewrite what an existing member was sold, and lowering its price
             * must not rewrite what they paid — the same reasoning as storing
             * an expense's ledger account on the expense rather than deriving
             * it at report time. `vip_tier_id` stays only for navigation
             * (nulled, never cascaded, if the tier is later deleted) and to
             * offer the current tier's terms when a membership renews.
             */
            $table->string('tier_name');
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('price_paid', 14, 2)->default(0);
            $table->string('currency', 3)->default('XAF');

            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('active');

            // The invoice this membership was sold on, if it was sold through
            // one — the same link Deal uses to tie the pipeline to the books.
            $table->foreignUlid('document_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->string('card_number')->nullable();

            // The QR/verification token printed on the membership card, if one
            // was issued — nulled rather than cascaded so revoking a card does
            // not delete the membership it belongs to.
            $table->foreignUlid('verification_token_id')->nullable()
                ->constrained('verification_tokens')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // A member's current standing, and the expiry sweep's worklist.
            $table->index(['company_id', 'contact_id', 'status']);
            $table->index(['company_id', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vip_memberships');
        Schema::dropIfExists('vip_tiers');
    }
};
