<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §8.2 of the master spec: a spreadsheet grid whose cells can pull live ERP
 * values (`OPES_SUM`, `OPES_LOOKUP`) instead of only holding numbers a human
 * typed. `cells` stores the raw formula/literal per reference (`"B2":
 * "=OPES_SUM(\"invoices\",\"status\",\"paid\")"`) — never a computed value,
 * so the sheet always re-evaluates against whatever the ERP currently says
 * rather than drifting from it, the same "living" principle as the
 * document editor's field chips.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spreadsheets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('title');
            $table->json('cells');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spreadsheets');
    }
};
