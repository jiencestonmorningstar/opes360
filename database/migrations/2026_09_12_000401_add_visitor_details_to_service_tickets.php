<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Who walked in, when they are not in the customer book.
         *
         * The service tables deliberately keep no copy of a customer — the
         * customer is a `contacts` row. A walk-in is the one legitimate
         * exception: the visitor identified themselves on a public page, and
         * letting that page create Contact rows would let anyone with the QR
         * fill the customer book with junk. So the visitor's own words live
         * here, on the ticket, and staff promote them to a real Contact only
         * when they choose to. Both stay null when the phone number matched
         * an existing contact.
         */
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->string('visitor_name', 120)->nullable()->after('contact_id');
            $table->string('visitor_phone', 40)->nullable()->after('visitor_name');
        });
    }

    public function down(): void
    {
        Schema::table('service_tickets', function (Blueprint $table) {
            $table->dropColumn(['visitor_name', 'visitor_phone']);
        });
    }
};
