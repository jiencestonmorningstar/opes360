<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Config for the new `compose_document` step type (§5 item 3 of the
     * Documents completion plan) — the template a step of that type drafts
     * when it is reached, and the workflow role to link the new document
     * back to the workflow's own subject under.
     *
     * Purely additive: existing rows get NULL here and every existing step
     * type (review/approval/signature/task) never reads either column, so
     * nothing about their behaviour changes.
     */
    public function up(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->string('compose_template')->nullable()->after('type');
            $table->string('compose_link_role')->nullable()->after('compose_template');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->dropColumn(['compose_template', 'compose_link_role']);
        });
    }
};
