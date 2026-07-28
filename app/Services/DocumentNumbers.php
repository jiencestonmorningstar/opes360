<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\Device;
use App\Models\NumberLease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

    /** Largest block a single device may hold at once. */
    protected const MAX_DEVICE_BLOCK = 200;

    public function next(DocumentType $type, ?CarbonImmutable $date = null): string
    {
        return $this->allocate($type->value, $type->prefix(), $date);
    }

    /** Receipts are not a DocumentType but share the same ledger and guarantees. */
    public function nextReceipt(?CarbonImmutable $date = null): string
    {
        return $this->allocate('receipt', 'RCP', $date);
    }

    /** Generated business documents — contracts, letters, certificates. */
    public function nextBusinessDocument(?CarbonImmutable $date = null): string
    {
        return $this->allocate('business_document', 'DOC', $date);
    }

    /**
     * Leases a block of numbers to a device so it can number documents with no
     * connection.
     *
     * A device holds at most one active lease per type and year: handing out a
     * second while the first still has numbers left would let the device consume
     * two disjoint ranges in parallel and produce out-of-order paper. Asking
     * again simply returns what it already holds, topped up only once exhausted.
     */
    public function leaseFor(Device $device, string $typeKey, int $size = 25, ?CarbonImmutable $date = null): NumberLease
    {
        $date ??= CarbonImmutable::now();
        $year = (int) $date->format('Y');
        $size = max(1, min(self::MAX_DEVICE_BLOCK, $size));

        return DB::transaction(function () use ($device, $typeKey, $year, $size) {
            $existing = NumberLease::query()
                ->where('document_type', $typeKey)
                ->where('year', $year)
                ->where('device_id', $device->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $existing->isExhausted()) {
                return $existing;
            }

            // The old block is closed at the number it reached, not at its end:
            // the unused tail is a recorded gap, never silently reissued.
            if ($existing !== null) {
                $existing->forceFill([
                    'status' => 'exhausted',
                    'void_unused_from' => $existing->next_available,
                ])->save();
            }

            $highest = (int) NumberLease::query()
                ->where('document_type', $typeKey)
                ->where('year', $year)
                ->lockForUpdate()
                ->max('range_end');

            return NumberLease::create([
                'document_type' => $typeKey,
                'year' => $year,
                'range_start' => $highest + 1,
                'range_end' => $highest + $size,
                'next_available' => $highest + 1,
                'device_id' => $device->id,
                'status' => 'active',
                'issued_at' => now(),
                // A lease is not open-ended: an abandoned device must not hold a
                // hole in the sequence forever.
                'expires_at' => now()->addDays(30),
            ]);
        });
    }

    /**
     * Accepts a number a device already put on paper offline.
     *
     * The number is only honoured if it falls inside a lease that device holds,
     * which is what stops a client inventing its own sequence. It is never
     * silently replaced: the customer is holding a document with this number on
     * it, so an unverifiable one is an error to surface, not to paper over.
     *
     * @return int The numeric value claimed.
     */
    public function claim(string $number, string $typeKey, Device $device): int
    {
        [$year, $value] = $this->parse($number);

        return DB::transaction(function () use ($number, $typeKey, $device, $year, $value) {
            $lease = NumberLease::query()
                ->where('document_type', $typeKey)
                ->where('year', $year)
                ->where('device_id', $device->id)
                ->where('range_start', '<=', $value)
                ->where('range_end', '>=', $value)
                ->lockForUpdate()
                ->first();

            if ($lease === null) {
                throw new RuntimeException("Number {$number} is outside any lease held by this device.");
            }

            if (in_array($lease->status, ['revoked', 'expired'], true)) {
                throw new RuntimeException("The lease covering {$number} is no longer valid.");
            }

            // Consumption only ever moves forward. A device may report its
            // numbers out of order after a long offline spell, and rewinding
            // would hand the same number out twice.
            if ($value >= (int) $lease->next_available) {
                $lease->next_available = $value + 1;
                $lease->status = $lease->next_available > $lease->range_end ? 'exhausted' : $lease->status;
                $lease->save();
            }

            return $value;
        });
    }

    /**
     * Splits `INV-2026-00042` into its year and sequence value.
     *
     * @return array{0: int, 1: int}
     */
    public function parse(string $number): array
    {
        if (! preg_match('/^([A-Z]+)-(\d{4})-(\d+)$/', trim($number), $matches)) {
            throw new RuntimeException("Malformed document number: {$number}");
        }

        return [(int) $matches[2], (int) $matches[3]];
    }

    /** The ledger key and human prefix for anything that draws a number. */
    public static function prefixFor(string $typeKey): string
    {
        return match ($typeKey) {
            'receipt' => 'RCP',
            'business_document' => 'DOC',
            default => DocumentType::from($typeKey)->prefix(),
        };
    }

    public function format(string $typeKey, int $year, int $value): string
    {
        return sprintf('%s-%d-%05d', self::prefixFor($typeKey), $year, $value);
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
