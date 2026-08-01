<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fiscal identity a DGI-acceptable invoice has to carry, beyond the
     * NIU and RCCM the companies table already holds as `tax_id` (encrypted,
     * with a blind index) and `registration_number`.
     *
     * `vat_registered` is deliberately separate from `tax_regime`. The regime
     * usually implies the answer — only the régime du réel collects TVA, above
     * the 50M FCFA turnover threshold — but a business can be mid-transition
     * between regimes, or hold an exemption, and an invoice must state what is
     * actually true rather than what the regime implies. Charging TVA without
     * being registered for it is an offence, so this is the flag the templates
     * and the calculator both read.
     *
     * `vat_rate` is stored per company rather than hardcoded at 19.25 because
     * OHADA spans seventeen countries on the same accounting system with
     * different rates, and this table is the only place that knows which one a
     * business trades in.
     */
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // liberatoire|simplifie|reel|exonere
            $table->string('tax_regime')->nullable()->after('vat_number');
            // Centre des impôts de rattachement — a mandatory mention.
            $table->string('tax_centre')->nullable()->after('tax_regime');
            $table->decimal('capital_social', 15, 2)->nullable()->after('tax_centre');
            $table->boolean('vat_registered')->default(false)->after('capital_social');
            /*
             * Cameroon's 19.25% is 17.5% TVA plus 10% centimes additionnels
             * communaux levied on the TVA itself. The effective rate is stored
             * rather than the two components: it is what gets applied, what the
             * invoice prints, and what an accountant reconciles against.
             */
            $table->decimal('vat_rate', 7, 4)->default(19.25)->after('vat_registered');
            /*
             * Whether unit prices are keyed tax-inclusive. A shop quoting one
             * shelf price works TTC; a consultancy quoting a fee works HT.
             * Getting this backwards misstates the tax owed in both directions,
             * so it is explicit rather than inferred.
             */
            $table->boolean('prices_include_tax')->default(false)->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'tax_regime', 'tax_centre', 'capital_social',
                'vat_registered', 'vat_rate', 'prices_include_tax',
            ]);
        });
    }
};
