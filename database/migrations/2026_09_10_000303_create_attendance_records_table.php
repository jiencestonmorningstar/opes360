<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who was in, and when.
     *
     * One row per employee per day, enforced by a unique key rather than by
     * the code that writes it. Two rows for one day is not a harmless
     * duplicate: every total built on this table doubles for that person and
     * no reader can tell which row is real. A re-import must overwrite.
     *
     * Deliberately not read by payroll. See the model.
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();

            // A timesheet is about one person and nobody else; it goes when
            // the staff file is truly deleted.
            $table->foreignUlid('employee_id')->constrained('employees')->cascadeOnDelete();

            $table->date('worked_on');

            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();

            // Derived from the two clock times when both are present, but
            // writable on its own: imports from clocking devices routinely
            // carry a duration and no times.
            $table->unsignedInteger('minutes_worked')->nullable();

            // present|absent|late|half_day|leave|holiday|remote
            $table->string('status')->default('present');

            // manual|import|device
            $table->string('source')->default('manual');

            $table->string('notes')->nullable();

            // users.id is a BIGINT, not a ULID. foreignUlid here passes on
            // SQLite and is rejected by MySQL at migrate time.
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // Named explicitly: MySQL rejects identifiers over 64 characters
            // and SQLite accepts them, so a generated name is a bug that only
            // appears in production.
            $table->unique(['company_id', 'employee_id', 'worked_on'], 'attendance_company_employee_day_unique');
            $table->index(['company_id', 'worked_on'], 'attendance_company_day_index');
            $table->index(['company_id', 'status'], 'attendance_company_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
