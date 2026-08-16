<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The standing duty: "we must declare TVA every quarter."
         *
         * One row per duty, not per occurrence. It carries the cadence and
         * the single answer to "when is the next one" — the number every
         * screen, reminder and dashboard reads. Keeping that answer in two
         * places is how a compliance register comes to disagree with itself,
         * and a register that disagrees with itself is worse than none: it is
         * trusted and wrong.
         */
        Schema::create('compliance_obligations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->string('reference')->nullable();     // the business's own file number
            $table->string('category')->default('other'); // tax|social|licence|insurance|permit|other

            // Who demands it. Free text on purpose: DGI, CNPS, the town hall,
            // a bank covenant and a customer's audit clause are all
            // "authorities" here, and a fixed list would exclude the fourth
            // one somebody actually has to satisfy.
            $table->string('authority')->nullable();
            $table->text('description')->nullable();

            // Null means one-off. Months rather than a frequency word so the
            // odd cadences a real business meets — every two months, every
            // eighteen — need no new vocabulary.
            $table->unsignedSmallInteger('interval_months')->nullable();

            /*
             * Where the next deadline is counted from, and the one genuinely
             * hard decision in this table.
             *
             * `due` — the authority sets the calendar. A quarterly return
             * filed six weeks late does not move the next quarter, so the
             * next date comes from the deadline that was just met.
             *
             * `completion` — the clock starts when the thing was done. A
             * licence renewed in March runs a year from March, because that
             * is what the new certificate says.
             *
             * A single rule cannot serve both, and getting it wrong is
             * invisible for one cycle and badly wrong by the fourth.
             */
            $table->string('schedule_basis')->default('due'); // due|completion

            $table->date('next_due_on')->nullable();

            // How far ahead this one starts warning. Per obligation, because
            // a declaration you prepare in an afternoon and a renewal needing
            // a bank attestation are not the same amount of notice.
            $table->unsignedSmallInteger('lead_days')->default(14);

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();

            // Whether filing it needs somebody's sign-off. The sign-off
            // itself runs through the platform workflow engine; this is only
            // the switch that says to ask.
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_active')->default(true);

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'name'], 'compliance_obligations_company_name_unq');
            $table->index(['company_id', 'next_due_on'], 'compliance_obligations_company_due_idx');
        });

        /*
         * One occurrence: the Q2 return, the 2026 licence.
         *
         * The obligation says what is next; this says what happened. Both are
         * needed and neither is derivable from the other — the obligation
         * rolls forward and forgets, and proving compliance to an inspector
         * means producing the history it forgot.
         *
         * `due_on` is copied here rather than looked up, deliberately. It is
         * the deadline this filing was actually against, and it must survive
         * the obligation moving on; without it "was this filed late" becomes
         * unanswerable the moment the next quarter is raised.
         */
        Schema::create('compliance_filings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('compliance_obligation_id')
                ->constrained('compliance_obligations')->cascadeOnDelete();

            $table->date('due_on');
            $table->date('completed_on')->nullable();

            // draft → submitted → completed, or rejected. Deliberately not a
            // second approval state machine: `submitted` simply means the
            // workflow engine has it, and the engine remains the authority on
            // who must act and whether they have.
            $table->string('status')->default('draft');

            $table->string('period_label')->nullable();  // "2026 Q2", "FY2026"
            $table->string('reference')->nullable();     // the authority's receipt number

            // What it cost to satisfy. Nullable because most obligations cost
            // nothing but effort, and a zero would read as a settled bill.
            $table->decimal('amount', 14, 2)->nullable();

            $table->text('notes')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'compliance_obligation_id'], 'compliance_filings_company_obl_idx');
            $table->index(['company_id', 'status'], 'compliance_filings_company_status_idx');
        });

        /*
         * The risk register.
         *
         * There is deliberately no stored score column. A score is
         * likelihood × impact and nothing else; storing it creates a third
         * number that can contradict the two it came from, and it will, the
         * first time a screen updates one of them without recalculating.
         * Ordering by severity is done in SQL from the two columns.
         */
        Schema::create('risks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('reference')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->default('operational');

            // The 5×5 scale, untreated: what this would be if nothing were
            // being done about it.
            $table->unsignedTinyInteger('likelihood');
            $table->unsignedTinyInteger('impact');

            /*
             * What it is with the controls actually in place — and null until
             * a person says so.
             *
             * Never derived from the controls attached. Deriving it would
             * lower the number the moment somebody typed a control into a
             * form, producing a reassuring figure nobody chose and no one
             * would notice was unearned.
             */
            $table->unsignedTinyInteger('residual_likelihood')->nullable();
            $table->unsignedTinyInteger('residual_impact')->nullable();

            $table->string('treatment')->default('mitigate'); // accept|mitigate|transfer|avoid
            $table->string('status')->default('open');        // open|monitoring|closed

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->date('identified_on')->nullable();
            $table->unsignedSmallInteger('review_interval_months')->nullable();
            $table->date('last_reviewed_on')->nullable();
            $table->date('next_review_on')->nullable();

            $table->date('closed_on')->nullable();
            $table->text('closure_reason')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status'], 'risks_company_status_idx');
            $table->index(['company_id', 'next_review_on'], 'risks_company_review_idx');
        });

        /*
         * What is being done about a risk. Separate rows because a real risk
         * has several, each with its own owner and its own state — one free
         * text "mitigation" field is what businesses already have in a
         * spreadsheet, and it is why nobody can say which mitigations exist.
         */
        Schema::create('risk_controls', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignUlid('risk_id')->constrained('risks')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('kind')->default('preventive'); // preventive|detective|corrective
            $table->string('status')->default('planned');  // planned|in_place|failed

            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->date('implemented_on')->nullable();

            // 1–5, and only meaningful once it is in place. How well it works
            // is a judgement, recorded as one, not inferred from its existence.
            $table->unsignedTinyInteger('effectiveness')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['company_id', 'risk_id'], 'risk_controls_company_risk_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_controls');
        Schema::dropIfExists('risks');
        Schema::dropIfExists('compliance_filings');
        Schema::dropIfExists('compliance_obligations');
    }
};
