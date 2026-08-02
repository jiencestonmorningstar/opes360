<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\Expense;
use App\Models\Item;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\ExpenseRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A delivery arriving.
 *
 * ── Why this exists separately from the expense form ─────────────────────
 *
 * Buying something and receiving it are two facts that usually happen together
 * and occasionally do not. The expense form records what the business owes and
 * charges it to 601; it has no line items, so it cannot say *what* arrived. The
 * stocktake corrects the shelf but only when somebody counts. Between the two
 * there was no way at all to say "a hundred sacks of cement came in today at
 * 5 200 each" — a shopkeeper had to hand-adjust the quantity and had nowhere to
 * put the price.
 *
 * That gap mattered more than it looks. The unit cost on an incoming movement
 * is the only thing the weighted average is built from, so a business with no
 * way to record one had a valuation resting entirely on the catalogue's
 * planning price.
 *
 * ── What it does, and what it leaves alone ───────────────────────────────
 *
 * It writes one incoming movement per line, with the price paid. It optionally
 * records the matching expense through the ordinary recorder, so the bill
 * reaches 601 and the TVA reaches 445 exactly as a typed expense would — one
 * path into the books, not two.
 *
 * It does *not* debit stock into 31. Under the inventaire intermittent the
 * purchase is a charge when it happens and the count carries what is left onto
 * the balance sheet; debiting 31 here as well would count the same crate twice.
 * See decision 14.
 */
class DeliveryReceiver
{
    public function __construct(protected StockLedger $stock) {}

    /**
     * @param  array<int, array{item_id: string, quantity: float|string, unit_cost: float|string|null}>  $lines
     * @param  array{received_on?: ?string, supplier_id?: ?string, reference?: ?string,
     *               location_id?: ?string, record_expense?: bool, vat_rate?: float,
     *               payment_method?: ?string, due_date?: ?string}  $options
     * @return array{movements: int, value: float, expense: ?Expense}
     *
     * @throws RuntimeException when nothing usable was given
     */
    public function receive(Company $company, array $lines, array $options = [], ?User $actor = null): array
    {
        $receivedOn = $options['received_on'] ?? now()->toDateString();

        $location = ($options['location_id'] ?? null)
            ? StockLocation::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)->find($options['location_id'])
            : null;

        /*
         * Only lines that name a product and a real quantity. A blank row is
         * somebody who added one and changed their mind, not an error worth
         * refusing the whole delivery over.
         */
        $usable = [];

        foreach ($lines as $line) {
            $quantity = round((float) ($line['quantity'] ?? 0), 3);

            if (($line['item_id'] ?? null) === null || $quantity <= 0) {
                continue;
            }

            $item = Item::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->find($line['item_id']);

            if ($item === null || ! $item->track_stock) {
                continue;
            }

            $usable[] = [
                'item' => $item,
                'quantity' => $quantity,
                'unit_cost' => ($line['unit_cost'] ?? null) === null || $line['unit_cost'] === ''
                    ? null
                    : round((float) $line['unit_cost'], 2),
            ];
        }

        if ($usable === []) {
            throw new RuntimeException('Nothing on this delivery is a tracked product with a quantity.');
        }

        return DB::transaction(function () use ($company, $usable, $options, $actor, $receivedOn, $location) {
            $value = 0.0;

            foreach ($usable as $line) {
                $this->stock->receive(
                    company: $company,
                    item: $line['item'],
                    quantity: $line['quantity'],
                    unitCost: $line['unit_cost'],
                    location: $location,
                    actor: $actor,
                    reason: 'purchase',
                    occurredAt: $receivedOn,
                );

                $value += $line['quantity'] * (float) ($line['unit_cost'] ?? 0);

                /*
                 * The catalogue's cost follows the last price paid. It is a
                 * planning figure — what the next one is expected to cost —
                 * and leaving it at a price from two years ago is how a
                 * product ends up being sold below what it now costs to buy.
                 * The books are unaffected: they read the movements.
                 */
                if ($line['unit_cost'] !== null && $line['unit_cost'] > 0) {
                    $line['item']->forceFill(['cost' => $line['unit_cost']])->save();
                }
            }

            $expense = null;

            if (($options['record_expense'] ?? false) && $value > 0) {
                $expense = app(ExpenseRecorder::class)->record([
                    'supplier_id' => $options['supplier_id'] ?? null,
                    'description' => 'Livraison — '.count($usable).' '
                        .(count($usable) === 1 ? 'article' : 'articles'),
                    'category' => 'goods',
                    'reference' => $options['reference'] ?? null,
                    'issue_date' => $receivedOn,
                    'due_date' => $options['due_date'] ?? null,
                    'amount' => round($value, 2),
                    'vat_rate' => (float) ($options['vat_rate'] ?? 0),
                    'payment_method' => $options['payment_method'] ?? null,
                ], $actor);
            }

            return [
                'movements' => count($usable),
                'value' => round($value, 2),
                'expense' => $expense,
            ];
        });
    }

    /** What a business last received, for the screen to show it happened. */
    public function recent(Company $company, int $limit = 8)
    {
        return StockMovement::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('reason', 'purchase')
            ->with('item:id,name')
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
