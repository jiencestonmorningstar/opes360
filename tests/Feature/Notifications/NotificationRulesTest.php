<?php

namespace Tests\Feature\Notifications;

use App\Models\AutomationRule;
use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Role;
use App\Models\User;
use App\Support\NotificationCategories;
use App\Support\NotificationChannels;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class NotificationRulesTest extends NotificationTestCase
{
    // ── Firing ────────────────────────────────────────────────────────────

    public function test_a_rule_notifies_its_recipients_when_its_event_happens(): void
    {
        $this->notificationRule();

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_a_rule_listening_for_a_different_event_does_not_fire(): void
    {
        $this->notificationRule(['event' => 'expense.paid']);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $this->owner->notifications()->count());
    }

    public function test_an_inactive_rule_does_not_fire(): void
    {
        $this->notificationRule(['is_active' => false]);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $this->owner->notifications()->count());
    }

    /** One condition language for the whole product — WorkflowConditions, unforked. */
    public function test_conditions_use_the_same_matcher_the_workflow_engine_uses(): void
    {
        $this->notificationRule([
            'conditions' => [['field' => 'total', 'operator' => '>', 'value' => 500000]],
        ]);

        $this->expense(100_000)->emitDomainEvent('expense.recorded');
        $this->assertSame(0, $this->owner->notifications()->count());

        $this->expense(900_000)->emitDomainEvent('expense.recorded');
        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_a_rule_belonging_to_another_company_never_sees_this_ones_events(): void
    {
        $this->notificationRule();

        $stranger = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-co', 'name' => 'Other', 'owner_id' => $stranger->id,
            'currency' => 'XAF', 'plan' => 'business', 'account_type' => 'active',
        ]);
        $this->joinCompany($other, $stranger, Role::OWNER);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $stranger->notifications()->count());
    }

    // ── Recipients, resolved now ──────────────────────────────────────────

    public function test_recipients_are_resolved_when_the_rule_fires_not_when_it_is_written(): void
    {
        $this->notificationRule(['recipients' => [['mode' => 'role', 'value' => Role::ACCOUNTANT]]]);

        // Nobody holds the role yet, so the first event reaches nobody.
        $this->expense()->emitDomainEvent('expense.recorded');

        $accountant = $this->memberAt(Role::ACCOUNTANT);

        // The same unchanged rule now reaches the person who holds it today.
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(1, $accountant->notifications()->count());
    }

    public function test_a_recipient_who_has_left_the_company_is_not_notified(): void
    {
        $accountant = $this->memberAt(Role::ACCOUNTANT);
        $this->company->users()->updateExistingPivot($accountant->id, ['status' => 'suspended']);

        $this->notificationRule(['recipients' => [['mode' => 'role', 'value' => Role::ACCOUNTANT]]]);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $accountant->notifications()->count());
    }

    public function test_a_rule_can_notify_whoever_raised_the_record(): void
    {
        $this->notificationRule(['recipients' => [['mode' => 'creator']]]);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_a_rule_can_notify_everyone_holding_a_permission(): void
    {
        $accountant = $this->memberAt(Role::ACCOUNTANT);

        $this->notificationRule(['recipients' => [['mode' => 'permission', 'value' => 'expenses.view']]]);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(1, $accountant->notifications()->count());
    }

    public function test_the_same_person_named_twice_is_notified_once(): void
    {
        $this->notificationRule([
            'recipients' => [['mode' => 'owner'], ['mode' => 'creator']],
        ]);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    // ── Preferences ───────────────────────────────────────────────────────

    public function test_a_person_who_muted_a_category_is_not_notified_on_that_channel(): void
    {
        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'category' => 'money',
            'channel' => 'in_app',
            'enabled' => false,
        ]);

        $this->notificationRule();

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $this->owner->id,
            'status' => 'suppressed',
            'reason' => 'muted',
        ]);
    }

    /** The blanket row: "stop telling me anything about this business." */
    public function test_a_blanket_mute_covers_every_category(): void
    {
        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'enabled' => false,
        ]);

        $this->notificationRule();
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $this->owner->notifications()->count());
    }

    public function test_the_most_specific_preference_wins_over_a_blanket_one(): void
    {
        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'enabled' => false,
        ]);
        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'category' => 'money',
            'channel' => 'in_app',
            'enabled' => true,
        ]);

        $this->notificationRule();
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    /** Muting must never be able to hide something that needs a decision. */
    public function test_a_critical_notification_ignores_a_mute(): void
    {
        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'enabled' => false,
        ]);

        $this->notificationRule(['severity' => 'critical']);
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    // ── Noise control ─────────────────────────────────────────────────────

    public function test_the_same_message_about_the_same_record_is_not_repeated_within_the_window(): void
    {
        $rule = $this->notificationRule(['dedupe_minutes' => 60]);
        $expense = $this->expense();

        $expense->emitDomainEvent('expense.recorded');
        $expense->emitDomainEvent('expense.recorded');
        $expense->emitDomainEvent('expense.recorded');

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(2, NotificationDelivery::query()->withoutGlobalScopes()
            ->where('rule_id', $rule->id)->where('reason', 'duplicate')->count());
    }

    public function test_a_repeat_after_the_window_has_passed_is_delivered(): void
    {
        $this->notificationRule(['dedupe_minutes' => 60]);
        $expense = $this->expense();

        $expense->emitDomainEvent('expense.recorded');

        $this->travel(2)->hours();

        $expense->emitDomainEvent('expense.recorded');

        $this->assertSame(2, $this->owner->notifications()->count());
    }

    public function test_two_different_records_are_never_deduplicated_against_each_other(): void
    {
        $this->notificationRule(['dedupe_minutes' => 60]);

        $this->expense()->emitDomainEvent('expense.recorded');
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(2, $this->owner->notifications()->count());
    }

    public function test_a_person_on_digest_is_held_rather_than_interrupted(): void
    {
        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'mode' => 'digest',
        ]);

        $this->notificationRule();
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $this->owner->id,
            'status' => 'deferred',
        ]);
    }

    public function test_a_digest_delivers_everything_held_as_one_message(): void
    {
        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'mode' => 'digest',
        ]);

        $this->notificationRule();
        $this->expense()->emitDomainEvent('expense.recorded');
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->travel(2)->hours();
        $this->artisan('notifications:digest')->assertSuccessful();

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(0, NotificationDelivery::query()->withoutGlobalScopes()
            ->where('status', 'deferred')->count());
    }

    public function test_a_critical_notification_is_never_held_for_a_digest(): void
    {
        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'mode' => 'digest',
        ]);

        $this->notificationRule(['severity' => 'critical']);
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    public function test_quiet_hours_hold_a_notification_rather_than_dropping_it(): void
    {
        $this->travelTo(now()->setTime(23, 0));

        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'quiet_from' => '22:00',
            'quiet_to' => '07:00',
        ]);

        $this->notificationRule();
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertDatabaseHas('notification_deliveries', [
            'user_id' => $this->owner->id,
            'status' => 'deferred',
            'reason' => 'quiet_hours',
        ]);

        $this->travelTo(now()->addDay()->setTime(8, 0));
        $this->artisan('notifications:digest')->assertSuccessful();

        $this->assertSame(1, $this->owner->notifications()->count());
    }

    // ── The log ───────────────────────────────────────────────────────────

    public function test_every_attempt_is_logged_so_i_never_got_told_is_answerable(): void
    {
        $rule = $this->notificationRule();
        $this->expense()->emitDomainEvent('expense.recorded');

        $delivery = NotificationDelivery::query()->withoutGlobalScopes()->first();

        $this->assertNotNull($delivery);
        $this->assertSame($rule->id, $delivery->rule_id);
        $this->assertSame($this->owner->id, $delivery->user_id);
        $this->assertSame('in_app', $delivery->channel);
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('expense.recorded', $delivery->event);
        $this->assertNotNull($delivery->sent_at);
    }

    public function test_the_log_is_scoped_to_the_company_that_sent_it(): void
    {
        $this->notificationRule();
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame($this->company->id, NotificationDelivery::query()->first()?->company_id);
    }

    // ── Channels ──────────────────────────────────────────────────────────

    public function test_email_and_in_app_are_the_only_channels_available_today(): void
    {
        $this->assertSame(['in_app', 'email'], array_keys(NotificationChannels::available()));
    }

    /**
     * A gateway channel is catalogued so a rule can be written for it, but
     * unavailable until somebody implements it — the rule must not silently
     * behave as though it sent.
     */
    public function test_an_unavailable_channel_is_logged_rather_than_pretended(): void
    {
        $this->notificationRule(['channels' => ['sms']]);
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertDatabaseHas('notification_deliveries', [
            'channel' => 'sms',
            'status' => 'suppressed',
            'reason' => 'channel_unavailable',
        ]);
    }

    public function test_a_rule_can_send_on_both_channels_at_once(): void
    {
        Notification::fake();

        $this->notificationRule(['channels' => ['in_app', 'email']]);
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(
            ['email', 'in_app'],
            NotificationDelivery::query()->withoutGlobalScopes()
                ->where('status', 'sent')->pluck('channel')->sort()->values()->all()
        );
    }

    // ── Catalogues ────────────────────────────────────────────────────────

    public function test_every_category_key_is_a_slug_with_a_label(): void
    {
        foreach (NotificationCategories::all() as $key => $definition) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $key);
            $this->assertNotEmpty($definition['label']);
        }
    }

    public function test_a_rule_may_not_be_written_against_an_uncatalogued_event(): void
    {
        $this->expectException(ValidationException::class);

        $this->notificationRule(['event' => 'expense.exploded']);
    }

    public function test_a_rule_may_not_be_written_against_an_uncatalogued_category(): void
    {
        $this->expectException(ValidationException::class);

        $this->notificationRule(['category' => 'gossip']);
    }

    // ── Automation keeps using the same path ──────────────────────────────

    /**
     * The automation runner's notify actions must go through the rules engine,
     * or a person who muted a category would still be interrupted by an
     * automation saying the same thing.
     */
    public function test_an_automation_notify_action_respects_the_same_preferences(): void
    {
        NotificationPreference::create([
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'enabled' => false,
        ]);

        AutomationRule::create([
            'name' => 'Tell the owner',
            'event' => 'expense.recorded',
            'action' => 'notify_user',
            'action_config' => ['user_id' => $this->owner->id, 'message' => 'Look at this'],
            'is_active' => true,
        ]);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $this->owner->notifications()->count());
        $this->assertDatabaseHas('notification_deliveries', ['status' => 'suppressed']);
    }
}
