<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Letting somebody else in.
 *
 * `company_user` already carried `status` and `invited_at`, so the shape of an
 * invitation was there and nothing could issue one — the settings screen said
 * as much, in so many words. What was missing is the part that makes an
 * invitation safe to send by email: a secret only the invited person has, and
 * a date after which it stops working.
 *
 * The token lives on the membership rather than in its own table because there
 * is exactly one live invitation per person per business — the unique index on
 * (company_id, user_id) already says so — and a separate table would let the
 * two disagree about who had been invited to what.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            // Nullable and unique: null for every member who joined before
            // invitations existed, and for everyone who has since accepted.
            // Accepting clears it, which is what makes a link single-use.
            $table->string('invitation_token', 64)->nullable()->unique()->after('invited_at');
            $table->timestamp('invitation_expires_at')->nullable()->after('invitation_token');
            $table->foreignId('invited_by')->nullable()->after('invitation_expires_at')
                ->constrained('users')->nullOnDelete();
        });

        /*
         * Somebody invited who has never used the product gets a users row with
         * no password — they choose one when they accept. Null rather than a
         * random unusable hash, because "this person has not set a password" is
         * a fact worth being able to read: the invitation screen asks for one
         * only when it is missing, and an existing user invited to a second
         * business is never asked to change theirs from a forwarded link.
         *
         * Safe against the login path: Laravel's hasher returns false for an
         * empty stored hash before it compares anything, and AuthController
         * refuses the account explicitly as well.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('company_user', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['invitation_token', 'invitation_expires_at']);
        });
    }
};
