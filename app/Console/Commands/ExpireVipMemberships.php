<?php

namespace App\Console\Commands;

use App\Services\VipMemberships;
use Illuminate\Console\Command;

/**
 * Marks VIP memberships past their end date as expired.
 *
 * Housekeeping, not enforcement. `VipMembership::isActive()` already refuses a
 * membership past its end date, so a night this does not run cannot hand
 * anybody a discount they are no longer entitled to — the lists and filters
 * simply show a stale status until it does.
 *
 * What it is for is the notification: a subscriber wanting to win a lapsed
 * member back needs to be told which membership lapsed, and nothing else in
 * the system notices the day a date passes.
 */
class ExpireVipMemberships extends Command
{
    protected $signature = 'opes:expire-vip-memberships';

    protected $description = 'Mark VIP memberships past their end date as expired';

    public function handle(VipMemberships $memberships): int
    {
        $count = $memberships->expireLapsed();

        $this->info($count === 0
            ? 'No memberships to expire.'
            : sprintf('Expired %d membership%s.', $count, $count === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
