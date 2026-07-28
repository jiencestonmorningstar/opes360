<?php

namespace App\Console\Commands;

use App\Models\NumberLease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Closes number leases that a device never came back for.
 *
 * A lost or replaced phone leaves a block of numbers permanently unusable. That
 * is fine — gaps are expected in this scheme — but only if every gap has a row
 * explaining it. An expired lease left `active` is worse than a closed one: it
 * looks like numbers that might still turn up, and a tax inspector asking "what
 * happened to invoices 226 to 250" deserves a dated answer rather than a shrug.
 *
 * Recording `void_unused_from` is the whole point of the command. Nothing is
 * deleted and no number is ever reissued.
 */
class ExpireNumberLeases extends Command
{
    protected $signature = 'opes:expire-leases {--dry-run : Show what would be closed without changing anything}';

    protected $description = 'Close expired device number leases and record their unused ranges';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Deliberately unscoped: this runs from the scheduler, which has no
        // current company, and must sweep every tenant.
        $expired = NumberLease::query()
            ->acrossAllCompanies()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('No leases have expired.');

            return self::SUCCESS;
        }

        foreach ($expired as $lease) {
            $unused = $lease->remaining();

            $this->line(sprintf(
                '  %s %s %d–%d · %d unused%s',
                $dryRun ? 'would close' : 'closed',
                $lease->document_type,
                $lease->range_start,
                $lease->range_end,
                $unused,
                $lease->device_id ? ' · device '.$lease->device_id : '',
            ));

            if ($dryRun) {
                continue;
            }

            DB::transaction(function () use ($lease, $unused) {
                $lease->forceFill([
                    'status' => 'expired',
                    // Null when the block was fully consumed: there is no gap to
                    // explain, and a value here would imply otherwise.
                    'void_unused_from' => $unused > 0 ? $lease->next_available : null,
                ])->save();
            });
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d lease%s.',
            $dryRun ? 'Would close' : 'Closed',
            $expired->count(),
            $expired->count() === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
