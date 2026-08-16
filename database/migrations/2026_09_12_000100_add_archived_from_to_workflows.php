<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Somewhere to put the definition an approval is halfway through.
     *
     * The admin screens let a business rewrite a workflow while approvals are
     * running against it. Rewriting the rows in place would change the rules
     * under an approval already in flight — steps renumbered, a step deleted
     * taking its assignments with it by cascade, an approver replaced after
     * somebody had already been asked. So an edit that would do that copies
     * the old definition aside first and moves the running approvals onto the
     * copy, which then never changes again.
     *
     * This column is what tells the copy apart from a workflow somebody wrote.
     * Without it an archived version would appear in the list as a workflow
     * the business could pick, which is the opposite of what it is: a frozen
     * record of rules that no longer apply to anything new.
     *
     * Deliberately no foreign key. If the original workflow is ever hard
     * deleted, the frozen copy must survive it — it is holding live approvals.
     */
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->ulid('archived_from_id')->nullable()->after('subject_type');

            $table->index(['company_id', 'archived_from_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'archived_from_id']);
            $table->dropColumn('archived_from_id');
        });
    }
};
