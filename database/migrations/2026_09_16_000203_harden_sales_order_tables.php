<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The hardening pass over the orders vertical — additive only.
 *
 * source_document_id: the quotation this order was converted from, so the
 * paper trail runs quotation → order → delivery note → invoice without a
 * copy anywhere. Nullable because most orders are drafted directly.
 *
 * credit_override_reason / credit_override_by: confirming an order for a
 * customer whose overdue balance exceeds their credit limit is refused
 * unless somebody writes down why they went ahead — and the written-down
 * why lives here, on the order, where an auditor will find it.
 *
 * delivery_note_lines.quantity_returned: how much of this delivered line
 * has come back, the running ceiling that makes "you cannot return more
 * than was delivered" a checkable fact rather than a hope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignUlid('source_document_id')->nullable()
                ->constrained('documents')->nullOnDelete();
            $table->string('credit_override_reason')->nullable();
            // Users are BIGINT ids, not ulids.
            $table->foreignId('credit_override_by')->nullable()
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('delivery_note_lines', function (Blueprint $table) {
            $table->decimal('quantity_returned', 15, 3)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_document_id');
            $table->dropConstrainedForeignId('credit_override_by');
            $table->dropColumn('credit_override_reason');
        });

        Schema::table('delivery_note_lines', function (Blueprint $table) {
            $table->dropColumn('quantity_returned');
        });
    }
};
