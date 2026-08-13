<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Giving money back.
     *
     * A refund is its own event, not the absence of a payment. The payment
     * still happened — the customer holds a receipt saying so, and that
     * receipt keeps verifying — so the record of it stays and this sits
     * beside it. Deleting the payment instead would leave a printed receipt
     * pointing at nothing, which is exactly the situation the verification
     * QR exists to prevent.
     *
     * That is also why there is no `refunded_amount` column on payments and
     * nothing else: a payment can be refunded in parts, by different people,
     * on different days, and each of those is a fact worth keeping separately.
     * What has been given back is the sum of these rows.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('payment_id')->constrained('payments')->cascadeOnDelete();

            // Denormalised so a refund can be found by customer without
            // joining through the payment, which is how the customer's own
            // statement reads it.
            $table->foreignUlid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);

            // How the money went back, which is not always how it came in:
            // cash taken at the counter is often returned by mobile money.
            $table->string('method', 30);
            $table->string('reference')->nullable();

            /*
             * Required by the service rather than merely nullable here. A
             * refund with no stated reason is the one entry in the books
             * nobody can explain a year later, and the person who could is
             * usually the one who no longer works there.
             */
            $table->string('reason');

            $table->timestamp('refunded_at');
            $table->foreignId('refunded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'refunded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
