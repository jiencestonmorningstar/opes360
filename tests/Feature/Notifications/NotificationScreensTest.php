<?php

namespace Tests\Feature\Notifications;

use App\Livewire\Settings\NotificationRules as RulesScreen;
use App\Livewire\Settings\NotificationSettings;
use App\Models\NotificationPreference;
use App\Models\NotificationRule;
use App\Models\Role;
use Livewire\Livewire;

class NotificationScreensTest extends NotificationTestCase
{
    // ── Writing rules ─────────────────────────────────────────────────────

    public function test_an_administrator_can_write_a_rule(): void
    {
        Livewire::actingAs($this->owner)
            ->test(RulesScreen::class)
            ->set('name', 'Tell finance')
            ->set('event', 'expense.recorded')
            ->set('category', 'money')
            ->set('title', 'An expense was recorded')
            ->set('recipientMode', 'role')
            ->set('recipientValue', Role::ACCOUNTANT)
            ->set('channels', ['in_app'])
            ->call('save')
            ->assertHasNoErrors();

        $rule = NotificationRule::query()->first();

        $this->assertNotNull($rule);
        $this->assertSame([['mode' => 'role', 'value' => Role::ACCOUNTANT]], $rule->recipients);
    }

    /**
     * Writing a rule is not a preference. Anybody who can write one can point
     * every approval alert in the business at themselves, or away from the
     * person who was supposed to sign.
     */
    public function test_a_cashier_cannot_open_the_rules_screen(): void
    {
        $cashier = $this->memberAt(Role::CASHIER);

        Livewire::actingAs($cashier)
            ->test(RulesScreen::class)
            ->assertForbidden();
    }

    public function test_a_rule_naming_an_unknown_event_is_refused_by_the_form(): void
    {
        Livewire::actingAs($this->owner)
            ->test(RulesScreen::class)
            ->set('name', 'Nonsense')
            ->set('event', 'expense.exploded')
            ->set('title', 'Boom')
            ->call('save')
            ->assertHasErrors('event');
    }

    public function test_a_rule_must_name_at_least_one_channel(): void
    {
        Livewire::actingAs($this->owner)
            ->test(RulesScreen::class)
            ->set('name', 'Silent')
            ->set('event', 'expense.recorded')
            ->set('title', 'Something happened')
            ->set('channels', [])
            ->call('save')
            ->assertHasErrors('channels');
    }

    /** A rule that stopped firing is the thing people need to look at. */
    public function test_a_rule_can_be_paused_rather_than_deleted(): void
    {
        $rule = $this->notificationRule();

        Livewire::actingAs($this->owner)
            ->test(RulesScreen::class)
            ->call('toggle', $rule->id);

        $this->assertFalse($rule->fresh()->is_active);
    }

    public function test_the_screen_shows_the_delivery_log(): void
    {
        $this->notificationRule();
        $this->expense()->emitDomainEvent('expense.recorded');

        Livewire::actingAs($this->owner)
            ->test(RulesScreen::class)
            ->assertSee('An expense was recorded')
            ->assertSee('Delivered');
    }

    // ── Preferences ───────────────────────────────────────────────────────

    /** Your own settings. A gate here would mean an alert nobody could mute. */
    public function test_anybody_can_open_their_own_notification_settings(): void
    {
        $cashier = $this->memberAt(Role::CASHIER);

        Livewire::actingAs($cashier)
            ->test(NotificationSettings::class)
            ->assertOk();
    }

    public function test_everything_is_on_before_anybody_touches_the_screen(): void
    {
        Livewire::actingAs($this->owner)
            ->test(NotificationSettings::class)
            ->assertSet('wanted.money.in_app', true)
            ->assertSet('mode', 'immediate');
    }

    public function test_switching_a_category_off_stops_the_notification_arriving(): void
    {
        Livewire::actingAs($this->owner)
            ->test(NotificationSettings::class)
            ->set('wanted.money.in_app', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->notificationRule();
        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertSame(0, $this->owner->notifications()->count());
    }

    public function test_quiet_hours_need_an_end_as_well_as_a_start(): void
    {
        Livewire::actingAs($this->owner)
            ->test(NotificationSettings::class)
            ->set('quietFrom', '22:00')
            ->set('quietTo', '')
            ->call('save')
            ->assertHasErrors('quietTo');
    }

    public function test_saving_twice_updates_the_same_row_rather_than_stacking_them(): void
    {
        $screen = Livewire::actingAs($this->owner)->test(NotificationSettings::class);

        $screen->set('wanted.money.in_app', false)->call('save');
        $screen->set('wanted.money.in_app', true)->call('save');

        $this->assertSame(1, NotificationPreference::query()
            ->where('user_id', $this->owner->id)
            ->where('category', 'money')
            ->where('channel', 'in_app')
            ->count());
    }

    /** The blanket row holds the settings that are not per-category. */
    public function test_the_summary_setting_is_stored_once_for_the_whole_business(): void
    {
        Livewire::actingAs($this->owner)
            ->test(NotificationSettings::class)
            ->set('mode', 'digest')
            ->set('quietFrom', '22:00')
            ->set('quietTo', '07:00')
            ->call('save');

        $blanket = NotificationPreference::query()
            ->where('user_id', $this->owner->id)
            ->where('category', '')
            ->where('channel', '')
            ->first();

        $this->assertSame('digest', $blanket->mode);
        $this->assertStringStartsWith('22:00', (string) $blanket->quiet_from);
    }
}
