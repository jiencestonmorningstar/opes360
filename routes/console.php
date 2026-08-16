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

/*
 * Renewal nudges: a week before a plan's billing period ends and again once
 * it has. Daily is the right grain — the command itself keeps per-cycle
 * state, so running it more often would send nothing extra and less often
 * would just make the "one week out" mail late.
 */
Schedule::command('opes:remind-plan-renewals')
    ->daily()
    ->at('06:45')
    ->withoutOverlapping();

/*
 * Low-stock alerts. Daily and batched into one message per business: an alert
 * per item, or per stock movement, would be filtered within a week — and a
 * filtered alert is the same as no alert. Early enough to act on before the
 * day's trading.
 */
Schedule::command('opes:alert-low-stock')
    ->daily()
    ->at('07:15')
    ->withoutOverlapping();

/*
 * Release what quiet hours held, and send the digests that are due.
 *
 * Hourly because quiet hours end on the hour somewhere: a message held at
 * 22:00 must go out when the recipient's morning starts, and a run that
 * happened once a day would either release it in the middle of the night or
 * sit on it until the following evening. The command is a no-op when nothing
 * is waiting, which is most hours.
 */
Schedule::command('notifications:digest')
    ->hourly()
    ->withoutOverlapping();

/*
 * Move SLA clocks: mark what has breached, and warn on what is about to.
 *
 * Every fifteen minutes. An hourly sweep on a four-hour response target means
 * a quarter of the warning window can pass before anybody is told, which turns
 * an early warning into a notification that it is already too late.
 */
Schedule::command('service:sla-sweep')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/*
 * Lapse VIP memberships whose term has ended.
 *
 * Just after midnight so a membership that ran to yesterday is expired before
 * the business opens, rather than reading as active for a morning. This is
 * housekeeping — VipMembership::isActive() already refuses a discount past the
 * end date, so a night it does not run costs a notification rather than money.
 */
Schedule::command('opes:expire-vip-memberships')
    ->daily()
    ->at('00:30')
    ->withoutOverlapping();

/*
 * Raise the invoices standing arrangements are due to produce.
 *
 * Just after the VIP sweep, and early, so a business that bills on the first of
 * the month finds the drafts waiting when it opens rather than during the day.
 *
 * Unlike the sweeps above this is not housekeeping: nothing else will bill the
 * customer if this does not run, so the service catches up every period a
 * schedule has fallen behind rather than only the most recent one.
 */
Schedule::command('opes:generate-recurring-invoices')
    ->daily()
    ->at('00:45')
    ->withoutOverlapping();

/*
 * Chase overdue invoices, for the businesses that asked us to.
 *
 * Mid-morning rather than overnight: a reminder timestamped 01:00 reads as
 * machinery, and one that lands while somebody is at their desk is likelier to
 * be acted on than one at the bottom of an overnight pile.
 */
Schedule::command('opes:send-dunning-reminders')
    ->dailyAt('09:15')
    ->withoutOverlapping();
