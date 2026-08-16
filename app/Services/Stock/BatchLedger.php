<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\Item;
use App\Models\StockBatch;
use App\Models\StockLocation;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock the business can name: which lot, which unit, when it goes off.
 *
 * Opt-in per product. Everything here refuses a product whose tracking_mode is
 * 'none' rather than inventing a lot for it — a product with one batch and a
 * hundred untraced units looks like a complete record and is not, and a recall
 * run against it would report the wrong customers.
 *
 * Quantities stay in the movement ledger, which keeps batches append-only for
 * free: a batch is depleted by writing a negative movement against it, never
 * by decrementing a column.
 */
class BatchLedger
{
    /**
     * Stock of a known lot arriving.
     *
     * The same lot number arriving a second time adds to the lot it already
     * knows rather than creating a twin — two rows called LOT-A would split a
     * recall in half and each half would look complete.
     *
     * @param  array{code?: string, expires_on?: string|null, received_on?: string|null, manufactured_on?: string|null, unit_cost?: float|null, supplier_reference?: string|null, note?: string|null, kind?: string}  $attributes
     */
    public function receive(
        Item $item,
        ?StockLocation $location,
        float $quantity,
        array $attributes = [],
        ?User $actor = null,
        string $reason = 'purchase',
    ): StockBatch {
        $this->assertTraceable($item);

        $code = trim((string) ($attributes['code'] ?? ''));

        if ($code === '') {
            throw new RuntimeException("{$item->name} is tracked by lot, so its arrival needs a lot number.");
        }

        $company = $this->company();

        return DB::transaction(function () use ($company, $item, $location, $quantity, $attributes, $actor, $reason, $code) {
            $batch = StockBatch::query()
                ->where('item_id', $item->id)
                ->where('code', $code)
                ->first();

            if ($batch === null) {
                $batch = StockBatch::create([
                    'company_id' => $company->id,
                    'item_id' => $item->id,
                    'code' => $code,
                    'kind' => $attributes['kind'] ?? ($item->tracksSerials() ? StockBatch::KIND_SERIAL : StockBatch::KIND_BATCH),
                    'expires_on' => $attributes['expires_on'] ?? null,
                    'received_on' => $attributes['received_on'] ?? now()->toDateString(),
                    'manufactured_on' => $attributes['manufactured_on'] ?? null,
                    'supplier_reference' => $attributes['supplier_reference'] ?? null,
                    'unit_cost' => isset($attributes['unit_cost']) ? round((float) $attributes['unit_cost'], 2) : null,
                    'note' => $attributes['note'] ?? null,
                    'created_by' => $actor?->id,
                ]);
            }

            if (abs($quantity) > 0.0005) {
                $this->append($batch, $location, round($quantity, 3), $reason, $actor, $attributes['unit_cost'] ?? null);
            }

            return $batch;
        });
    }

    /**
     * Individually numbered units arriving.
     *
     * Each serial is its own lot of one. Written in a single transaction so a
     * duplicate halfway down a scanned list rejects the whole delivery rather
     * than leaving half of it received and the scanner's count wrong.
     *
     * @param  array<int, string>  $serials
     * @return Collection<int, StockBatch>
     */
    public function receiveSerials(
        Item $item,
        ?StockLocation $location,
        array $serials,
        array $attributes = [],
        ?User $actor = null,
        string $reason = 'purchase',
    ): Collection {
        $this->assertTraceable($item);

        $serials = array_values(array_filter(array_map('trim', $serials), fn (string $s) => $s !== ''));

        if ($serials === []) {
            throw new RuntimeException('A serialised delivery needs at least one serial number.');
        }

        if (count(array_unique($serials)) !== count($serials)) {
            throw new RuntimeException('The same serial number appears twice on this delivery.');
        }

        return DB::transaction(function () use ($item, $location, $serials, $attributes, $actor, $reason) {
            $known = StockBatch::query()
                ->where('item_id', $item->id)
                ->whereIn('code', $serials)
                ->pluck('code');

            if ($known->isNotEmpty()) {
                throw new RuntimeException(
                    'Already recorded for '.$item->name.': '.$known->implode(', ').'.'
                );
            }

            return collect($serials)->map(fn (string $serial) => $this->receive(
                $item,
                $location,
                1,
                $attributes + ['code' => $serial, 'kind' => StockBatch::KIND_SERIAL],
                $actor,
                $reason,
            ));
        });
    }

    /** What is left of one lot. */
    public function quantityOf(StockBatch $batch): float
    {
        return round((float) StockMovement::query()->where('stock_batch_id', $batch->id)->sum('quantity'), 3);
    }

    /**
     * Take stock out of a named lot.
     *
     * Refuses to take more than the lot holds: a negative lot balance is not a
     * shortage anybody can go and look for, it is a bookkeeping impossibility
     * that makes the recall list wrong.
     */
    public function issue(
        StockBatch $batch,
        float $quantity,
        string $reason = 'sale',
        ?User $actor = null,
        ?StockLocation $location = null,
        mixed $reference = null,
    ): StockMovement {
        $quantity = round(abs($quantity), 3);
        $available = $this->quantityOf($batch);

        if ($quantity - $available > 0.0005) {
            throw new RuntimeException("Lot {$batch->code} only has {$available} left.");
        }

        return $this->append($batch, $location, -$quantity, $reason, $actor, null, $reference);
    }

    /**
     * First-expired-first-out.
     *
     * Picks across the lots of one product, soonest expiry first, and writes
     * the movements. Lots with no expiry go last: a lot nobody dated is not
     * "expires never", it is "unknown", and shipping the dated stock first is
     * the choice that loses least if the guess is wrong.
     *
     * Already-expired lots are skipped rather than picked first — FEFO's point
     * is to sell stock before it goes off, not to ship stock that already has.
     *
     * @return array<int, array{batch: StockBatch, quantity: float}>
     */
    public function pick(
        Item $item,
        ?StockLocation $location,
        float $quantity,
        string $reason = 'sale',
        ?User $actor = null,
        mixed $reference = null,
    ): array {
        $this->assertTraceable($item);

        $wanted = round($quantity, 3);

        if ($wanted <= 0) {
            return [];
        }

        return DB::transaction(function () use ($item, $location, $wanted, $reason, $actor, $reference) {
            $candidates = $this->pickable($item, $location);

            $plan = [];
            $left = $wanted;

            foreach ($candidates as $batch) {
                if ($left <= 0.0005) {
                    break;
                }

                $have = $this->quantityAt($batch, $location);

                if ($have <= 0.0005) {
                    continue;
                }

                $take = round(min($have, $left), 3);
                $plan[] = ['batch' => $batch, 'quantity' => $take];
                $left = round($left - $take, 3);
            }

            if ($left > 0.0005) {
                $short = round($wanted - $left, 3);

                throw new RuntimeException(
                    "Only {$short} of {$item->name} is available in unexpired lots; {$wanted} was asked for."
                );
            }

            foreach ($plan as $row) {
                $this->append($row['batch'], $location, -$row['quantity'], $reason, $actor, null, $reference);
            }

            return $plan;
        });
    }

    /**
     * Lots of one product still holding stock.
     *
     * @return Collection<int, StockBatch>
     */
    public function onHandFor(Item $item, ?StockLocation $location = null): Collection
    {
        return $this->remaining(
            StockBatch::query()->where('item_id', $item->id)->orderBy('code'),
            $location
        );
    }

    /**
     * Stock that will go off soon and is still on the shelf.
     *
     * Already-expired lots are excluded on purpose: "sell this before it goes
     * off" and "this has gone off, quarantine it" are different jobs for
     * different people, and merging them buries the urgent one.
     *
     * @return Collection<int, StockBatch>
     */
    public function expiringWithin(int $days = 30, ?Item $item = null): Collection
    {
        $query = StockBatch::query()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', now()->toDateString())
            ->whereDate('expires_on', '<=', now()->addDays($days)->toDateString())
            ->orderBy('expires_on');

        if ($item) {
            $query->where('item_id', $item->id);
        }

        return $this->remaining($query);
    }

    /**
     * Stock that has gone off and has not been written down yet.
     *
     * @return Collection<int, StockBatch>
     */
    public function expired(?Item $item = null): Collection
    {
        $query = StockBatch::query()
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', now()->toDateString())
            ->orderBy('expires_on');

        if ($item) {
            $query->where('item_id', $item->id);
        }

        return $this->remaining($query);
    }

    /**
     * Which lots a given quantity of a product came from — the recall answer.
     *
     * @return Collection<int, StockBatch>
     */
    public function batchesBehind(string $referenceType, string $referenceId): Collection
    {
        $ids = StockMovement::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereNotNull('stock_batch_id')
            ->pluck('stock_batch_id')
            ->unique();

        return $ids->isEmpty() ? collect() : StockBatch::query()->whereIn('id', $ids)->get();
    }

    // ───────────────────────────────────────────────────────── internals ──

    /**
     * @return Collection<int, StockBatch>
     */
    protected function pickable(Item $item, ?StockLocation $location): Collection
    {
        $batches = StockBatch::query()
            ->where('item_id', $item->id)
            ->where(fn (Builder $q) => $q->whereNull('expires_on')
                ->orWhereDate('expires_on', '>=', now()->toDateString()))
            ->get();

        // Undated lots last. Sorting on a null date directly would put them
        // first in SQL on some drivers and last on others, and "which lot did
        // we ship" is not a question that may depend on the database.
        return $batches->sortBy([
            fn (StockBatch $a, StockBatch $b) => ($a->expires_on === null ? 1 : 0) <=> ($b->expires_on === null ? 1 : 0),
            fn (StockBatch $a, StockBatch $b) => ($a->expires_on?->timestamp ?? 0) <=> ($b->expires_on?->timestamp ?? 0),
            fn (StockBatch $a, StockBatch $b) => $a->created_at <=> $b->created_at,
        ])->values();
    }

    /**
     * Drop the lots with nothing left in them.
     *
     * @param  Builder<StockBatch>  $query
     * @return Collection<int, StockBatch>
     */
    protected function remaining(Builder $query, ?StockLocation $location = null): Collection
    {
        return $query->get()
            ->filter(fn (StockBatch $batch) => $this->quantityAt($batch, $location) > 0.0005)
            ->values();
    }

    protected function quantityAt(StockBatch $batch, ?StockLocation $location = null): float
    {
        $query = StockMovement::query()->where('stock_batch_id', $batch->id);

        if ($location) {
            $query->where('stock_location_id', $location->id);
        }

        return round((float) $query->sum('quantity'), 3);
    }

    protected function append(
        StockBatch $batch,
        ?StockLocation $location,
        float $quantity,
        string $reason,
        ?User $actor,
        float|int|string|null $unitCost = null,
        mixed $reference = null,
    ): StockMovement {
        return StockMovement::create([
            'company_id' => $batch->company_id,
            'item_id' => $batch->item_id,
            'stock_location_id' => $location?->id,
            'stock_batch_id' => $batch->id,
            'quantity' => round($quantity, 3),
            'unit_cost' => $unitCost !== null ? round((float) $unitCost, 2) : null,
            'reason' => $reason,
            'reference_type' => $reference ? $reference::class : null,
            'reference_id' => $reference?->id,
            'user_id' => $actor?->id,
            'occurred_at' => now(),
        ]);
    }

    protected function assertTraceable(Item $item): void
    {
        if (! $item->isTraceable()) {
            throw new RuntimeException(
                "{$item->name} is not tracked by lot or serial number. Turn tracking on for it first."
            );
        }
    }

    protected function company(): Company
    {
        return app(CurrentCompany::class)->get()
            ?? throw new RuntimeException('Cannot record a stock lot without a current company.');
    }
}
