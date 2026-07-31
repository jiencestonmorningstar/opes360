<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opes Forms — customisable forms a business builds and shares (Module 16).
     *
     * Field definitions live as ordered JSON on the form rather than as child
     * rows: the builder edits the whole set at once in one Livewire component,
     * a form's fields are never queried independently, and each response
     * stores its answers keyed by field id — so relational fields would buy
     * joins nobody performs. Deleting a field keeps old answers intact under
     * their key, which is exactly the behaviour reviewers of past responses
     * expect.
     */
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // [{id, type, label, help, required, options: []}, ...] in display order.
            $table->json('fields')->nullable();

            $table->string('status')->default('draft'); // draft|open|closed
            // The public fill URL. Random, not derived from the id: a share
            // link must not let anyone enumerate a business's other forms.
            $table->string('share_token', 32)->unique();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        Schema::create('form_responses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('form_id')->constrained()->cascadeOnDelete();

            // {field_id: answer, ...} — answers survive later field edits.
            $table->json('answers');

            $table->timestamps();

            $table->index(['form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_responses');
        Schema::dropIfExists('forms');
    }
};
