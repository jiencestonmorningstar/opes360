<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Bring already-seeded charts up to the plan's own labels.
     *
     * Verifying the chart against the SYSCOHADA source data showed four of the
     * seeded labels were the friendly short form rather than the official one
     * — and for two of them the short form is actively misleading: 521 is
     * specifically "Banques locales" because 522 is banks in other OHADA
     * states, and 571 is "Caisse siège social" because 572 is a branch till.
     * A chart that says just "Banques" misdescribes an account whose meaning
     * is positional.
     *
     * Only rows still carrying the exact old default are touched, so any name
     * an accountant has set themselves survives. Numbers never change — the
     * books reference accounts by id, and the labels are presentation.
     */
    private const RENAMES = [
        ['401', 'Fournisseurs', 'Fournisseurs, dettes en compte'],
        ['444', 'État, TVA due', 'État, TVA due ou crédit de TVA'],
        ['521', 'Banques', 'Banques locales'],
        ['571', 'Caisse', 'Caisse siège social'],
    ];

    public function up(): void
    {
        foreach (self::RENAMES as [$number, $old, $new]) {
            DB::table('ledger_accounts')
                ->where('number', $number)
                ->where('name', $old)
                ->update(['name' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as [$number, $old, $new]) {
            DB::table('ledger_accounts')
                ->where('number', $number)
                ->where('name', $new)
                ->update(['name' => $old]);
        }
    }
};
