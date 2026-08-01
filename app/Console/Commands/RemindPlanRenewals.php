<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Notifications\SubscriptionRenewalReminder;
use Illuminate\Console\Command;

/**
 * The renewal nudges: one a week before a plan's billing period ends, one
 * once it has ended. Runs daily; the per-cycle state on the company is what
 * keeps that from meaning seven copies of the same mail.
 *
 * Only self-service billing is nudged. A company whose plan an admin set by
 * hand has no plan_renews_at, and demo/trial accounts have nothing to renew.
 * Suspended companies are skipped too — "please pay us" and "you are
 * suspended" is not a pairing anyone should receive.
 */
class RemindPlanRenewals extends Command
{
    protected $signature = 'opes:remind-plan-renewals';

    protected $description = 'Notify owners whose plan billing period is ending or has ended';

    public function handle(): int
    {
        $companies = Company::query()
            ->where('account_type', 'active')
            ->whereNotNull('plan_renews_at')
            ->whereNull('suspended_at')
            ->where('plan_renews_at', '<=', now()->addDays(7))
            ->with('owner')
            ->get();

        $sent = 0;

        foreach ($companies as $company) {
            $for = $company->plan_renews_at->toDateString();

            // The stored stage only counts if it belongs to THIS renewal date.
            // A payment moves plan_renews_at forward, the dates stop matching,
            // and the new cycle starts with a clean slate.
            $stage = $company->renewal_reminder_for?->toDateString() === $for
                ? $company->renewal_reminder_stage
                : null;

            $next = match (true) {
                now()->gte($company->plan_renews_at) && $stage !== 'overdue' => 'overdue',
                now()->lt($company->plan_renews_at) && $stage === null => 'upcoming',
                default => null,
            };

            if ($next === null) {
                continue;
            }

            $company->owner?->notify(new SubscriptionRenewalReminder(
                (string) $company->plan,
                $company->plan_renews_at,
                $next,
            ));

            $company->forceFill([
                'renewal_reminder_stage' => $next,
                'renewal_reminder_for' => $for,
            ])->saveQuietly();

            $sent++;
        }

        $this->info("Sent {$sent} renewal reminder(s).");

        return self::SUCCESS;
    }
}
