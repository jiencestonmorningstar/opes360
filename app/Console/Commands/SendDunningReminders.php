<?php

namespace App\Console\Commands;

use App\Services\Dunning;
use Illuminate\Console\Command;

/**
 * Emails customers whose invoices have passed a reminder step.
 *
 * Only for companies that have switched chasing on. These messages go to a
 * business's own customers under its own name, so it is opt-in rather than
 * something a deploy turns on for everybody.
 */
class SendDunningReminders extends Command
{
    protected $signature = 'opes:send-dunning-reminders';

    protected $description = 'Send overdue-invoice reminders for companies that have enabled them';

    public function handle(Dunning $dunning): int
    {
        $result = $dunning->run();

        if ($result['companies'] === 0) {
            $this->info('No business has reminders switched on.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Sent %d %s across %d %s.',
            $result['sent'],
            $result['sent'] === 1 ? 'reminder' : 'reminders',
            $result['companies'],
            $result['companies'] === 1 ? 'business' : 'businesses',
        ));

        // Said out loud: an invoice skipped for want of an email address is a
        // debt nobody is chasing, and the business should know it is happening.
        if ($result['skipped'] > 0) {
            $this->warn(sprintf(
                '%d %s skipped — no email address, or below the minimum.',
                $result['skipped'],
                $result['skipped'] === 1 ? 'invoice was' : 'invoices were',
            ));
        }

        return self::SUCCESS;
    }
}
