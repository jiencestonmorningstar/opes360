<?php

namespace App\Services\Procurement;

use App\Models\Company;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Replenishment;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Turn checked replenishment suggestions into ordinary draft requisitions.
 *
 * Deliberately thin. There is no replenishment order, no second requisition
 * type and no direct path to a purchase order: a suggestion somebody accepts
 * becomes a draft PurchaseRequisition through Requisitions::create(), exactly
 * as if a person had typed it, and from there it submits to the shared
 * workflow engine like every other request to spend money. Software that
 * noticed a shelf was low never gets to commit a franc on its own.
 *
 * One requisition per supplier, because that is the shape the next step
 * needs: an approved requisition goes to one supplier as an RFQ or a direct
 * order, and a mixed-supplier requisition would have to be pulled apart by
 * hand at exactly the moment the work was supposed to be done.
 */
class Replenisher
{
    public function __construct(protected Requisitions $requisitions) {}

    /**
     * @param  array<int, string>  $itemIds  the checked rows
     * @return Collection<int, PurchaseRequisition>
     */
    public function accept(array $itemIds, User $actor): Collection
    {
        $company = $this->company();

        $rows = (new Replenishment($company))->rows()
            ->filter(fn (array $row) => in_array($row['item']->id, $itemIds, true)
                && $row['suggested_quantity'] > 0);

        // Nothing checked, or everything checked was stale by the time the
        // button was pressed — refused rather than an empty requisition
        // nobody can route.
        if ($rows->isEmpty()) {
            throw new RuntimeException('Nothing on the list needs ordering.');
        }

        return $rows
            // Items nobody has named a supplier for still need buying; they
            // group together into one requisition for procurement to source.
            ->groupBy(fn (array $row) => $row['supplier']?->id ?? '')
            ->map(function (Collection $group) use ($actor) {
                $supplier = $group->first()['supplier'];

                return $this->requisitions->create([
                    'title' => $supplier
                        ? 'Replenishment — '.$supplier->name
                        : 'Replenishment — supplier to be sourced',
                    'justification' => 'Raised from the replenishment screen: stock at or heading below its reorder level.',
                    'notes' => $supplier
                        ? sprintf('Suggested supplier: %s (about %d days to deliver).', $supplier->name, (int) $group->max('lead_days'))
                        : null,
                    'lines' => $group->map(fn (array $row) => [
                        'item_id' => $row['item']->id,
                        'description' => $row['item']->name,
                        'quantity' => $row['suggested_quantity'],
                        'unit' => $row['item']->unit ?? 'unit',
                        'estimated_unit_price' => $row['estimated_unit_price'] ?? 0.0,
                    ])->values()->all(),
                ], $actor);
            })
            ->values();
    }

    protected function company(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new RuntimeException('Cannot replenish stock without a current company.');
    }
}
