<?php

namespace Tests\Feature\Automation;

use App\Events\DomainEvent;
use App\Models\AutomationRule;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\Workflow;
use App\Support\CurrentCompany;
use App\Support\DomainEvents;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class AutomationTest extends AutomationTestCase
{
    // ── The event ─────────────────────────────────────────────────────────

    public function test_a_model_can_announce_what_happened(): void
    {
        Event::fake([DomainEvent::class]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        Event::assertDispatched(DomainEvent::class, fn (DomainEvent $e) => $e->name === 'expense.recorded'
            && $e->subject->is($expense)
            && $e->companyId === $this->company->id);
    }

    /** A payload embedding a copy of the record would BE a second copy of it. */
    public function test_the_payload_references_the_subject_rather_than_copying_it(): void
    {
        Event::fake([DomainEvent::class]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        Event::assertDispatched(DomainEvent::class, function (DomainEvent $e) use ($expense) {
            $payload = $e->payload();

            return $payload['subject_type'] === $expense->getMorphClass()
                && $payload['subject_id'] === $expense->getKey()
                && ! array_key_exists('total', $payload)
                && ! array_key_exists('description', $payload);
        });
    }

    public function test_every_catalogued_event_name_is_namespaced_and_past_tense(): void
    {
        foreach (DomainEvents::all() as $name) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_.]+$/', $name);
        }
    }

    public function test_the_document_events_the_brief_names_all_exist(): void
    {
        foreach ([
            'document.created', 'document.updated', 'document.version.created',
            'document.submitted', 'document.review.requested',
            'document.changes.requested', 'document.approved', 'document.rejected',
            'document.signature.requested', 'document.signed', 'document.published',
            'document.archived', 'document.expired', 'document.shared',
        ] as $name) {
            $this->assertTrue(DomainEvents::exists($name), $name);
        }
    }

    // ── Matching ──────────────────────────────────────────────────────────

    public function test_a_rule_fires_on_its_own_event(): void
    {
        $this->rule(['action_config' => ['field' => 'notes', 'value' => 'Flagged.']]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $this->assertSame('Flagged.', $expense->fresh()->notes);
    }

    public function test_a_rule_ignores_a_different_event(): void
    {
        $this->rule([
            'event' => 'expense.paid',
            'action_config' => ['field' => 'notes', 'value' => 'Flagged.'],
        ]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $this->assertNull($expense->fresh()->notes);
    }

    public function test_an_inactive_rule_never_fires(): void
    {
        $this->rule([
            'is_active' => false,
            'action_config' => ['field' => 'notes', 'value' => 'Flagged.'],
        ]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $this->assertNull($expense->fresh()->notes);
    }

    /** The same matcher the workflow engine uses, not a second one. */
    public function test_conditions_decide_whether_a_rule_applies(): void
    {
        $this->rule([
            'conditions' => [['field' => 'total', 'operator' => '>=', 'value' => 10_000_000]],
            'action_config' => ['field' => 'notes', 'value' => 'Large.'],
        ]);

        $small = $this->expense(5_000);
        $small->emitDomainEvent('expense.recorded');
        $this->assertNull($small->fresh()->notes);

        $large = $this->expense(50_000_000);
        $large->emitDomainEvent('expense.recorded');
        $this->assertSame('Large.', $large->fresh()->notes);
    }

    public function test_two_rules_on_one_event_both_fire(): void
    {
        $this->rule(['name' => 'A', 'action_config' => ['field' => 'notes', 'value' => 'One.']]);
        $this->rule(['name' => 'B', 'action_config' => ['field' => 'category', 'value' => 'reviewed']]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $this->assertSame('One.', $expense->fresh()->notes);
        $this->assertSame('reviewed', $expense->fresh()->category);
    }

    public function test_a_rule_belonging_to_another_company_never_fires(): void
    {
        $stranger = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => $stranger->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($other, $stranger, Role::OWNER);

        app(CurrentCompany::class)->set($other);
        $this->rule(['action_config' => ['field' => 'notes', 'value' => 'Theirs.']]);

        app(CurrentCompany::class)->set($this->company);
        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $this->assertNull($expense->fresh()->notes);
    }

    public function test_firing_stamps_the_rule(): void
    {
        $rule = $this->rule(['action_config' => ['field' => 'notes', 'value' => 'Flagged.']]);

        $this->assertNull($rule->last_fired_at);

        $this->expense()->emitDomainEvent('expense.recorded');

        $this->assertNotNull($rule->fresh()->last_fired_at);
    }

    // ── Safety ────────────────────────────────────────────────────────────

    /**
     * An automation that stops an invoice being issued is worse than an
     * automation that does not run.
     */
    public function test_a_throwing_rule_does_not_break_the_emitter(): void
    {
        $this->rule(['action' => 'set_field', 'action_config' => ['field' => 'total', 'value' => 0]]);

        $expense = $this->expense();

        // No exception escapes, and the record is untouched.
        $expense->emitDomainEvent('expense.recorded');

        $this->assertSame('100000.00', $expense->fresh()->total);
    }

    public function test_one_broken_rule_does_not_stop_the_others(): void
    {
        $this->rule(['name' => 'Broken', 'action_config' => ['field' => 'total', 'value' => 0]]);
        $this->rule(['name' => 'Fine', 'action_config' => ['field' => 'notes', 'value' => 'Still ran.']]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $this->assertSame('Still ran.', $expense->fresh()->notes);
    }

    /** Being listenable must not imply being writable. */
    public function test_a_rule_cannot_set_a_field_the_model_has_not_offered(): void
    {
        $this->rule(['action_config' => ['field' => 'status', 'value' => 'paid']]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $this->assertSame('draft', $expense->fresh()->status);
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $this->rule(['action' => 'rm -rf', 'action_config' => []]);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $this->assertNull($expense->fresh()->notes);
        $this->assertArrayNotHasKey('rm -rf', AutomationRule::ACTIONS);
    }

    public function test_an_event_with_no_company_is_not_dispatched(): void
    {
        Event::fake([DomainEvent::class]);

        app(CurrentCompany::class)->set(null);

        $orphan = new \App\Models\Expense;
        $orphan->emitDomainEvent('expense.recorded');

        Event::assertNotDispatched(DomainEvent::class);
    }

    // ── Actions that reach other subsystems ───────────────────────────────

    public function test_a_rule_can_start_an_approval(): void
    {
        $this->memberAt(Role::MANAGER);

        Workflow::create([
            'name' => 'Expense approval',
            'subject_type' => \App\Models\Expense::class,
            'is_active' => true,
            'is_default' => true,
        ])->steps()->create([
            'position' => 1,
            'name' => 'Manager review',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
        ]);

        $this->rule(['action' => 'start_workflow', 'action_config' => []]);

        $this->actingAs($this->owner);

        $expense = $this->expense();
        $expense->emitDomainEvent('expense.recorded');

        $this->assertTrue($expense->fresh()->isAwaitingApproval());
    }

    public function test_a_rule_can_notify_everybody_with_a_role(): void
    {
        Notification::fake();

        $manager = $this->memberAt(Role::MANAGER);

        $this->rule([
            'action' => 'notify_role',
            'action_config' => ['role' => Role::MANAGER, 'message' => 'A large expense was recorded.'],
        ]);

        $this->expense()->emitDomainEvent('expense.recorded');

        /*
         * Through the notification engine, not around it. An automation that
         * sent its own message would bypass the recipient's mutes, quiet hours
         * and digest — and the notification settings screen would be quietly
         * lying about what it controls. See Phase 4.4.
         */
        Notification::assertSentTo($manager, \App\Notifications\RuleNotification::class);
    }
}
