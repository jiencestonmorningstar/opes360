<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the business has promised, and when it is open to keep it.
         *
         * The working calendar lives on the policy rather than in config,
         * because it is a commercial term rather than a setting: a business
         * can sell a 24/7 contract to one customer and 08:00–17:00 to
         * another, and both are true at the same time.
         */
        Schema::create('service_sla_policies', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            /*
             * The one policy applied to a ticket that names none. Exactly one
             * per company — enforced in the model rather than by a partial
             * unique index, because MySQL has no filtered index and a unique
             * on (company_id, is_default) would forbid a second non-default
             * policy rather than a second default one.
             */
            $table->boolean('is_default')->default(false);

            /*
             * business — count only inside the windows below.
             * calendar  — count wall-clock time, for a 24/7 contract.
             *
             * A named mode rather than an empty schedule meaning "always
             * open": a policy whose windows were deleted by accident would
             * otherwise silently become the strictest contract in the
             * product instead of failing loudly.
             */
            $table->string('clock')->default('business');

            // The business's own timezone. "Is it Saturday" asked of a UTC
            // instant is wrong by up to a day for anybody not on Greenwich.
            $table->string('timezone')->default('UTC');

            // {"mon": [["08:00","17:00"]], ...} — a list per day so a lunch
            // break is two windows rather than a special case.
            $table->json('business_hours')->nullable();

            // ["2026-12-25", ...] — dates the business is shut regardless of
            // the weekday. Held here, not in a holidays table, because they
            // are part of the promise: two policies can disagree about them.
            $table->json('holidays')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'is_active'], 'service_sla_policies_active_idx');
        });

        /*
         * The promise itself, per priority. A row rather than four columns on
         * the policy, so a business that only sells two priorities is not
         * forced to invent targets for the other two.
         */
        Schema::create('service_sla_targets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('sla_policy_id')->constrained('service_sla_policies')->cascadeOnDelete();

            $table->string('priority');  // low|normal|high|urgent

            // Minutes of clock time, whichever clock the policy names.
            $table->unsignedInteger('response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();

            $table->timestamps();

            $table->unique(['sla_policy_id', 'priority'], 'service_sla_targets_priority_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_sla_targets');
        Schema::dropIfExists('service_sla_policies');
    }
};
