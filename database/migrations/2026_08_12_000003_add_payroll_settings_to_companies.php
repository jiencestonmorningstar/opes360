<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The handful of payroll figures that are genuinely a property of the
     * business rather than of the law.
     *
     * Its CNPS employer number goes on every declaration. Its occupational
     * risk group and its family-allowance regime are assigned to it by CNPS
     * according to what it does, and both change the employer's bill without
     * touching a single payslip's net — which is exactly why they belong on
     * the company and not in a global config anyone might edit for one
     * business and change for all of them.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('cnps_employer_number')->nullable()->after('tax_centre');
            $table->string('cnps_risk_group', 1)->default('a')->after('cnps_employer_number');
            $table->string('cnps_family_regime')->default('general')->after('cnps_risk_group');

            // Per-company overrides of the withholding switches in
            // config/payroll.php — chiefly the redevance audiovisuelle, whose
            // band amounts a business may want its accountant to confirm
            // before it withholds them from anybody.
            $table->json('payroll_settings')->nullable()->after('cnps_family_regime');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'cnps_employer_number',
                'cnps_risk_group',
                'cnps_family_regime',
                'payroll_settings',
            ]);
        });
    }
};
