<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One interviewer's scorecard for one interview.
     *
     * Deliberately simple — a 1-to-5 rating and free notes. A structured
     * competency matrix is the kind of thing that gets configured once,
     * abandoned, and then blocks the interviewer from writing what they
     * actually think. Rating is nullable because the row is created when the
     * panel is chosen, before anybody has an opinion.
     */
    public function up(): void
    {
        Schema::create('interview_feedback', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->foreignUlid('interview_id')->constrained('interviews')->cascadeOnDelete();

            // users.id is a BIGINT everywhere in this product; foreignId, not
            // foreignUlid. Cascade: feedback is that person's words about that
            // conversation, meaningless without either end.
            $table->foreignId('interviewer_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedTinyInteger('rating')->nullable(); // 1..5
            $table->text('notes')->nullable();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            // One scorecard per interviewer per interview; a rethink edits it.
            $table->unique(
                ['interview_id', 'interviewer_id'],
                'interview_feedback_interviewer_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interview_feedback');
    }
};
