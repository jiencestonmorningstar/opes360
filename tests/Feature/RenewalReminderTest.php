<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Notifications\SubscriptionRenewalReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The renewal nudges. The command runs daily, so the property that matters
 * most is that a reminder for one renewal date is sent exactly once — and
 * that a successful payment, by moving the date, resets the cycle.
 */
class RenewalReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function company(array $overrides = []): Company
    {
        $owner = User::factory()->create();

        return Company::create(array_merge([
            'slug' => 'acme-'.fake()->unique()->numerify('####'),
            'name' => 'Acme Sarl',
            'owner_id' => $owner->id,
            'currency' => 'XAF',
            'account_type' => 'active',
            'plan' => 'growth',
        ], $overrides));
    }

    protected function run_reminders(): void
    {
        $this->artisan('opes:remind-plan-renewals')->assertSuccessful();
    }

    public function test_the_upcoming_reminder_is_sent_once_inside_the_final_week(): void
    {
        Notification::fake();

        $company = $this->company(['plan_renews_at' => now()->addDays(5)]);

        $this->run_reminders();
        $this->run_reminders();

        Notification::assertSentToTimes($company->owner, SubscriptionRenewalReminder::class, 1);
        $this->assertSame('upcoming', $company->fresh()->renewal_reminder_stage);
    }

    public function test_nothing_is_sent_while_the_renewal_is_still_far_off(): void
    {
        Notification::fake();

        $this->company(['plan_renews_at' => now()->addDays(20)]);

        $this->run_reminders();

        Notification::assertNothingSent();
    }

    public function test_the_overdue_reminder_follows_once_the_date_passes(): void
    {
        Notification::fake();

        $company = $this->company(['plan_renews_at' => now()->addDays(3)]);

        // Inside the final week: the upcoming nudge.
        $this->run_reminders();

        // The date passes unpaid: one overdue nudge, and only one, however
        // many mornings the schedule fires after that.
        $company->forceFill(['plan_renews_at' => now()->subDay()])->save();
        $this->run_reminders();
        $this->run_reminders();

        Notification::assertSentToTimes($company->owner, SubscriptionRenewalReminder::class, 2);
        $this->assertSame('overdue', $company->fresh()->renewal_reminder_stage);
    }

    public function test_a_renewal_payment_resets_the_cycle(): void
    {
        Notification::fake();

        $company = $this->company(['plan_renews_at' => now()->addDays(2)]);

        $this->run_reminders();
        Notification::assertSentToTimes($company->owner, SubscriptionRenewalReminder::class, 1);

        // The owner pays; the biller pushes the date a month out. The stored
        // reminder state belongs to the old date, so the new cycle is clean —
        // silent until its own final week arrives.
        $company->forceFill(['plan_renews_at' => now()->addMonth()])->save();
        $this->run_reminders();
        Notification::assertSentToTimes($company->owner, SubscriptionRenewalReminder::class, 1);

        $company->forceFill(['plan_renews_at' => now()->addDays(4)])->save();
        $this->run_reminders();
        Notification::assertSentToTimes($company->owner, SubscriptionRenewalReminder::class, 2);
    }

    public function test_accounts_with_nothing_to_renew_are_left_alone(): void
    {
        Notification::fake();

        // Admin-assigned plan: no renewal date at all.
        $this->company(['plan_renews_at' => null]);
        // Demo and trial: nothing to renew yet.
        $this->company(['account_type' => 'demo', 'plan_renews_at' => now()->addDay()]);
        $this->company(['account_type' => 'trial', 'plan_renews_at' => now()->addDay()]);
        // Suspended: "please pay us" and "you are suspended" is not a pairing.
        $this->company(['plan_renews_at' => now()->addDay(), 'suspended_at' => now()]);

        $this->run_reminders();

        Notification::assertNothingSent();
    }

    public function test_a_plan_already_overdue_when_reminders_arrive_skips_straight_to_overdue(): void
    {
        Notification::fake();

        // An install that adopts reminders with a lapsed plan should hear
        // "overdue" once — not an "ends soon" mail about a date long past.
        $company = $this->company(['plan_renews_at' => now()->subDays(10)]);

        $this->run_reminders();
        $this->run_reminders();

        Notification::assertSentToTimes($company->owner, SubscriptionRenewalReminder::class, 1);
        $this->assertSame('overdue', $company->fresh()->renewal_reminder_stage);
    }
}
