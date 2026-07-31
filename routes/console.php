<?php

use App\Models\SyncReceipt;
use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work.
 *
 * Requires a single cron entry on the server:
 *   * * * * * cd /path/to/opes360 && php artisan schedule:run >> /dev/null 2>&1
 *
 * See docs/DEPLOYMENT.md.
 */

/*
 * Close number leases a device never came back for, recording the unused range
 * so every gap in the invoice sequence has a dated explanation. Hourly rather
 * than daily: a business that loses a phone in the morning should not carry an
 * unexplained hole in its books until midnight.
 */
Schedule::command('opes:expire-leases')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Prune sync receipts. They exist so a replayed envelope is recognised rather
 * than written twice; a device offline for three months has bigger problems
 * than a duplicate. Without this the table becomes the largest in the database.
 */
Schedule::command('model:prune', ['--model' => [SyncReceipt::class]])
    ->daily()
    ->at('03:15');

/*
 * Demo accounts convert to a free trial 14 days after signup, automatically
 * and without locking anyone out. Daily is enough — nothing time-critical
 * depends on the exact hour it flips.
 */
Schedule::command('opes:convert-expired-demos')
    ->daily()
    ->at('03:30');
