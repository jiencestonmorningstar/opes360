<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            /*
             * The reminder ladder: how many days past due each step fires, and
             * whether chasing is switched on at all.
             *
             * Off by default, and deliberately so. Reminders go to customers
             * under the business's name, and switching that on for every
             * existing tenant during a deploy would send mail nobody asked for
             * about invoices that may well have been settled in cash.
             */
            $table->json('dunning')->nullable()->after('branding');
        });

        Schema::create('dunning_reminders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUlid('contact_id')->constrained('contacts')->cascadeOnDelete();

            // Which rung of the ladder this was, in days past due.
            $table->unsignedSmallInteger('step');
            $table->unsignedInteger('days_overdue');
            $table->decimal('balance', 15, 2);
            $table->string('channel')->default('mail');
            $table->string('sent_to')->nullable();
            $table->timestamp('sent_at');

            $table->timestamps();

            /*
             * The whole point of this table: a document may be reminded once
             * per rung and never again. Without it a nightly sweep would send
             * the same "30 days overdue" email every night for a month, and a
             * customer who is chased daily stops reading anything you send.
             */
            $table->unique(['document_id', 'step']);
            $table->index(['company_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_reminders');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('dunning');
        });
    }
};
