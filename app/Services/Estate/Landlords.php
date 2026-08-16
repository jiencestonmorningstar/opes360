<?php

namespace App\Services\Estate;

use App\Models\Expense;
use App\Models\Property;
use App\Models\User;
use App\Services\ExpenseRecorder;
use App\Support\LandlordStatement;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The money side of managing somebody else's building.
 *
 * ── Why the payout is an Expense ───────────────────────────────────────────
 *
 * The vertical books collected rent as agency revenue (rent invoices are
 * ordinary invoices), so what goes back to the landlord is a cost of the
 * letting activity — recorded through ExpenseRecorder like every other franc
 * that leaves. Payables, settlement, the AC journal, the audit trail and the
 * AP aging all come free, and there is deliberately NO parallel payment path
 * here. Category `rent` lands it on 622 « Locations et charges locatives »,
 * which is exactly what rent paid over to an owner is.
 *
 * The expense is pinned to the property, so the next statement shows it as a
 * "paid out" line and the running balance keeps telling the truth.
 */
class Landlords
{
    /**
     * A cost incurred on the building — a repair, a guard, a water bill the
     * agency covered on the landlord's behalf. An ordinary expense through
     * the one recorder, then pinned to the property the way a maintenance
     * ticket is pinned to its unit.
     *
     * @param  array{description: string, amount: float, category?: string, supplier_id?: ?string,
     *               reference?: ?string, issue_date?: ?string, due_date?: ?string,
     *               payment_method?: ?string, vat_rate?: float, notes?: ?string}  $data
     */
    public function recordPropertyExpense(Property $property, array $data, ?User $actor = null): Expense
    {
        $expense = app(ExpenseRecorder::class)->record([
            'supplier_id' => $data['supplier_id'] ?? null,
            'description' => $data['description'],
            'category' => $data['category'] ?? 'maintenance',
            'reference' => $data['reference'] ?? null,
            'issue_date' => $data['issue_date'] ?? now()->toDateString(),
            'due_date' => $data['due_date'] ?? null,
            'amount' => (float) $data['amount'],
            'vat_rate' => (float) ($data['vat_rate'] ?? 0),
            'payment_method' => $data['payment_method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $actor);

        // The recorder takes only its own columns; the pin on the map is ours.
        $expense->forceFill(['property_id' => $property->id])->save();

        return $expense->refresh();
    }

    /**
     * Turn the statement's closing balance into a payable to the landlord.
     *
     * Computed, never typed blind: the amount defaults to what the statement
     * says is owed, and paying out more than that is refused — an agency that
     * hands a landlord money it has not collected is lending, and lending is
     * not this button.
     *
     * @param  array{from: string|CarbonInterface, to: string|CarbonInterface,
     *               amount?: ?float, due_date?: ?string, payment_method?: ?string,
     *               notes?: ?string}  $data
     */
    public function payOut(Property $property, array $data, ?User $actor = null): Expense
    {
        $landlord = $property->landlord;

        if ($landlord === null) {
            throw new RuntimeException(
                'This property has no landlord on record — a self-owned building has nobody to pay out.'
            );
        }

        $from = Carbon::parse($data['from'])->startOfDay();
        $to = Carbon::parse($data['to'])->endOfDay();

        /*
         * The cap is the running balance up to TODAY, not just the period's
         * close: a payout recorded five minutes ago is dated today, after the
         * period's end, and a cap that could not see it would let the same
         * period be paid out twice.
         */
        $horizon = $to->gt(now()) ? $to : Carbon::now()->endOfDay();
        $statement = (new LandlordStatement($landlord, $from, $horizon))->build();
        $owed = $statement['closing_balance'];

        if ($owed <= 0) {
            throw new RuntimeException(
                'Nothing is owed to '.$landlord->displayName().' for this period — the statement closes at '
                .number_format($owed, 2).'.'
            );
        }

        $amount = isset($data['amount']) && $data['amount'] !== null
            ? round((float) $data['amount'], 2)
            : $owed;

        if ($amount <= 0) {
            throw new RuntimeException('A payout must be for more than nothing.');
        }

        if ($amount - $owed > 0.005) {
            throw new RuntimeException(
                'That is more than the statement says is owed ('.number_format($owed, 2).').'
            );
        }

        $expense = app(ExpenseRecorder::class)->record([
            'supplier_id' => $landlord->id,
            'description' => 'Landlord payout — '.$property->name.', '
                .$from->toDateString().' to '.$to->toDateString(),
            'category' => 'rent',
            'reference' => 'LL-PAYOUT '.$from->toDateString().'..'.$to->toDateString(),
            'issue_date' => now()->toDateString(),
            'due_date' => $data['due_date'] ?? now()->addDays(7)->toDateString(),
            'amount' => $amount,
            'vat_rate' => 0,
            'payment_method' => $data['payment_method'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $actor);

        $expense->forceFill(['property_id' => $property->id])->save();

        return $expense->refresh();
    }
}
