<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // The trigger: a name from App\Support\DomainEvents.
            $table->string('event');

            /*
             * The same {field, operator, value} shape workflow_steps.conditions
             * uses, evaluated by the same matcher. A second condition language
             * would be exactly the duplication the brief forbids — and the
             * second one would be the one that grew an eval.
             */
            $table->json('conditions')->nullable();

            // A closed set. See App\Services\Automation\ActionRunner.
            $table->string('action');
            $table->json('action_config')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_fired_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'event', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rules');
    }
};
