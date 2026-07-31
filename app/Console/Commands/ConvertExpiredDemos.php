<?php

namespace App\Console\Commands;

use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Moves a demo account into a free trial once its 14 days are up.
 *
 * Deliberately not a lockout: nothing about the account changes except the
 * label. A demo that expired at 2am should not be the reason someone loses
 * an hour of work they were mid-way through when they read the email.
 */
class ConvertExpiredDemos extends Command
{
    protected $signature = 'opes:convert-expired-demos';

    protected $description = 'Move demo accounts past their 14-day window into a free trial';

    public function handle(): int
    {
        $converted = Company::query()
            ->where('account_type', 'demo')
            ->where('demo_expires_at', '<=', now())
            ->get();

        foreach ($converted as $company) {
            $company->endDemo();
        }

        $this->info("Converted {$converted->count()} expired demo account(s) to trial.");

        return self::SUCCESS;
    }
}
