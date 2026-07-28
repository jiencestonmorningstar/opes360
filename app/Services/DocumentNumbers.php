<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\NumberLease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Allocates document numbers from the lease ledger.
 *
 * The server draws from device-less leases (the "server pool"); devices lease
 * their own blocks in Phase 4. Both paths go through the same ledger, so every
 * number ever allocated — used, unused or voided — has an auditable row, which is
 * what makes sequence gaps defensible to a tax authority.
 * See docs/architecture/offline-sync.md §5.
 */
class DocumentNumbers
{
    /** Server pool block size. Devices get smaller blocks sized to their usage. */
    protected const BLOCK_SIZE = 1000;

    public function next(DocumentType $type, ?CarbonImmutable $date = null): string
    {
        return $this->allocate($type->value, $type->prefix(), $date);
    }

    /** Receipts are not a DocumentType but share the same ledger and guarantees. */
    public function nextReceipt(?CarbonImmutable $date = null): string
    {
        return $this->allocate('receipt', 'RCP', $date);
    }

    protected function allocate(string $typeKey, string $prefix, ?CarbonImmutable $date): string
    {
        $date ??= CarbonImmutable::now();
        $year = (int) $date->format('Y');

        // The transaction + row lock make concurrent issuance safe: two requests
        // issuing at once must not read the same next_available.
        $value = DB::transaction(function () use ($typeKey, $year) {
            $lease = NumberLease::query()
                ->where('document_type', $typeKey)
                ->where('year', $year)
                ->where('status', 'active')
                ->whereNull('device_id')
                ->orderBy('range_start')
                ->lockForUpdate()
                ->first();

            if ($lease === null) {
                $lease = $this->openBlock($typeKey, $year);
            }

            $number = (int) $lease->next_available;

            $lease->next_available = $number + 1;

            if ($lease->next_available > $lease->range_end) {
                $lease->status = 'exhausted';
            }

            $lease->save();

            return $number;
        });

        return sprintf('%s-%d-%05d', $prefix, $year, $value);
    }

    /**
     * Opens the next server block directly after the highest allocated range —
     * including device leases, so a server block can never overlap numbers a
     * device is consuming offline.
     */
    protected function openBlock(string $typeKey, int $year): NumberLease
    {
        $highest = (int) NumberLease::query()
            ->where('document_type', $typeKey)
            ->where('year', $year)
            ->max('range_end');

        return NumberLease::create([
            'document_type' => $typeKey,
            'year' => $year,
            'range_start' => $highest + 1,
            'range_end' => $highest + self::BLOCK_SIZE,
            'next_available' => $highest + 1,
            'status' => 'active',
            'issued_at' => now(),
        ]);
    }
}
