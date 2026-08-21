<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What `opes:remind-pending-signatures` reads to decide who has already been
 * nudged recently — without it, a daily reminder sweep would mail every
 * pending signer every single day it runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_document_signatures', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable()->after('declined_reason');
        });
    }

    public function down(): void
    {
        Schema::table('business_document_signatures', function (Blueprint $table) {
            $table->dropColumn('last_reminded_at');
        });
    }
};
