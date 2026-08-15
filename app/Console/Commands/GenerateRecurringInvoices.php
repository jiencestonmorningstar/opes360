<?php

namespace App\Console\Commands;

use App\Services\RecurringInvoices;
use Illuminate\Console\Command;

/**
 * Raises the invoices that standing arrangements are due to produce.
 *
 * Unlike the VIP expiry sweep, this one is not housekeeping — nothing else in
 * the system will bill the customer if this does not run. A night missed is a
 * month's revenue not invoiced, which is why the service catches up every
 * period a schedule has fallen behind rather than only the latest one.
 *
 * Invoices arrive as drafts unless a schedule is explicitly set to issue
 * automatically, so the ordinary outcome of this command is a queue of drafts
 * for somebody to glance at, not paper posted to customers unattended.
 */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'opes:generate-recurring-invoices';

    protected $description = 'Raise invoices for standing arrangements that are due';

    public function handle(RecurringInvoices $recurring): int
    {
        $result = $recurring->run();

        if ($result['created'] === 0) {
            $this->info('Nothing was due.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Raised %d %s from %d %s.',
            $result['created'],
            $result['created'] === 1 ? 'invoice' : 'invoices',
            $result['schedules'],
            $result['schedules'] === 1 ? 'schedule' : 'schedules',
        ));

        // Said out loud rather than swallowed. A schedule that hits the
        // catch-up cap is still behind after this run, and a business whose
        // billing is silently lagging would have no way to find out.
        foreach ($result['capped'] as $name) {
            $this->warn(sprintf(
                '"%s" hit the catch-up limit of %d and is still behind. Check its start date.',
                $name,
                RecurringInvoices::CATCH_UP_LIMIT,
            ));
        }

        return self::SUCCESS;
    }
}
