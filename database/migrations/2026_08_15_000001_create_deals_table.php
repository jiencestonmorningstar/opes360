<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The sales pipeline.
     *
     * Until now the customer book could tell you who someone is and what they
     * owe, but not that you are halfway through selling them something. Work
     * in progress lived in people's heads and in WhatsApp threads, and the
     * first time the system heard about a sale was the invoice — by which
     * point there is nothing left to manage.
     *
     * A deal is deliberately thin: a contact, an amount you expect, a stage,
     * and a date you expect it to close. No lead scoring, no forecasting
     * models, no AI. Those are the parts of a CRM that need a volume of data a
     * small business does not have, and they are the parts that go stale and
     * start lying first.
     *
     * `contact_id` is nullable because a lead is often a name and a phone
     * number before it is a customer record — forcing the contact to exist
     * first is how a pipeline ends up with junk in the customer book.
     */
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignUlid('contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();

            // Who is chasing it. Nulled rather than cascaded so a staff member
            // leaving does not delete the pipeline they built.
            $table->foreignId('owner_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('title');
            $table->text('notes')->nullable();

            // Held as a name + phone for leads with no contact record yet.
            $table->string('lead_name')->nullable();
            $table->string('lead_phone')->nullable();

            $table->string('stage')->default('lead');
            $table->decimal('value', 15, 2)->default(0);
            $table->string('currency', 3)->default('XAF');

            $table->date('expected_close_on')->nullable();

            /*
             * Set when the deal leaves the board in either direction, so
             * "how long does a sale take" is answerable without reconstructing
             * it from an audit log.
             */
            $table->timestamp('closed_at')->nullable();
            $table->string('lost_reason')->nullable();

            /*
             * The invoice this became. The link is what stops the pipeline and
             * the books disagreeing about whether a sale happened.
             */
            $table->foreignUlid('document_id')->nullable()
                ->constrained('documents')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The board query: one company's open deals, oldest stage first.
            $table->index(['company_id', 'stage']);
            $table->index(['company_id', 'expected_close_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
