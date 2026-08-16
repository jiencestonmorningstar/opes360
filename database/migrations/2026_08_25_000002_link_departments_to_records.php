<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignUlid('department_id')->nullable()->after('department')
                ->constrained('departments')->nullOnDelete();

            $table->index(['company_id', 'department_id']);
        });

        Schema::table('business_documents', function (Blueprint $table) {
            $table->foreignUlid('department_id')->nullable()->after('folder_id')
                ->constrained('departments')->nullOnDelete();

            $table->index(['company_id', 'department_id']);
        });

        Schema::table('business_document_folders', function (Blueprint $table) {
            $table->foreignUlid('department_id')->nullable()->after('owner_id')
                ->constrained('departments')->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Every distinct department name already typed against an employee becomes
     * a real department, and that employee is linked to it.
     *
     * The string column is left exactly as it was. It is the business's data,
     * it is what they typed, and if this backfill guesses wrong about two
     * spellings being one department, they must be able to see that and fix
     * it. A migration that tidies a schema by deleting what somebody wrote is
     * not a migration.
     */
    protected function backfill(): void
    {
        $rows = DB::table('employees')
            ->select('company_id', 'department')
            ->whereNotNull('department')
            ->where('department', '!=', '')
            ->distinct()
            ->get();

        foreach ($rows as $row) {
            $id = (string) Str::ulid();

            DB::table('departments')->insert([
                'id' => $id,
                'company_id' => $row->company_id,
                'name' => $row->department,
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('employees')
                ->where('company_id', $row->company_id)
                ->where('department', $row->department)
                ->update(['department_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('business_document_folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('business_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
