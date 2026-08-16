<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentSignature;
use App\Models\Expense;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\Role;
use App\Models\Workflow;
use App\Models\WorkflowAssignment;
use App\Models\WorkflowInstance;

/**
 * §2.15 — the daily reminder sweep.
 *
 * The command never mails anybody itself. It raises domain events, and the
 * notification rules decide who hears about them — which is why every test
 * here configures a rule and then proves the reminder by the delivery row the
 * dispatcher wrote, never by Event::fake(), which would disable the very
 * listener under test.
 */
class RemindersTest extends DocumentsTestCase
{
    /** @param  array<string, mixed>  $attributes */
    protected function rule(string $event, array $attributes = []): NotificationRule
    {
        return NotificationRule::create(array_merge([
            'name' => 'Remind people about '.$event,
            'event' => $event,
            'category' => 'documents',
            'severity' => 'normal',
            'title' => 'Reminder: '.$event,
            'body' => 'Something is waiting on you.',
            'recipients' => [['mode' => 'owner']],
            'channels' => ['in_app'],
            'is_active' => true,
        ], $attributes));
    }

    protected function overdueAssignment(?string $dueOn = null): WorkflowAssignment
    {
        $workflow = Workflow::create([
            'name' => 'Expense approval',
            'subject_type' => Expense::class,
            'is_active' => true,
        ]);

        $step = $workflow->steps()->create([
            'position' => 1,
            'name' => 'Manager sign-off',
            'type' => 'approval',
            'approver_mode' => 'role',
            'approver_role' => Role::MANAGER,
            'quorum' => 'any',
            'due_days' => 2,
        ]);

        $expense = Expense::create([
            'description' => 'Generator fuel',
            'category' => 'fuel',
            'issue_date' => now()->toDateString(),
            'amount' => 100_000,
            'total' => 100_000,
            'status' => 'draft',
            'recorded_by' => $this->owner->id,
        ]);

        $instance = WorkflowInstance::create([
            'workflow_id' => $workflow->id,
            'subject_type' => $expense->getMorphClass(),
            'subject_id' => (string) $expense->id,
            'status' => 'running',
            'position' => 1,
            'started_by' => $this->owner->id,
            'started_at' => now(),
        ]);

        return WorkflowAssignment::create([
            'workflow_instance_id' => $instance->id,
            'workflow_step_id' => $step->id,
            'user_id' => $this->owner->id,
            'status' => 'pending',
            'due_on' => $dueOn ?? now()->subDays(3)->toDateString(),
        ]);
    }

    protected function sentDeliveries(string $event): int
    {
        return NotificationDelivery::query()
            ->withoutGlobalScopes()
            ->where('event', $event)
            ->where('status', 'sent')
            ->count();
    }

    public function test_an_overdue_assignment_produces_exactly_one_delivery(): void
    {
        $this->rule('workflow.assignment.overdue');
        $this->overdueAssignment();

        $this->artisan('opes:remind-actions')->assertSuccessful();

        $this->assertSame(1, $this->sentDeliveries('workflow.assignment.overdue'));
    }

    public function test_a_second_run_the_same_day_produces_nothing_more(): void
    {
        $this->rule('workflow.assignment.overdue');
        $this->overdueAssignment();

        $this->artisan('opes:remind-actions')->assertSuccessful();
        $this->artisan('opes:remind-actions')->assertSuccessful();

        $this->assertSame(1, $this->sentDeliveries('workflow.assignment.overdue'));
    }

    public function test_an_assignment_due_today_is_not_overdue(): void
    {
        // Date-cast columns sit at midnight, so "past" would be true from
        // 00:01 on the due day itself. Due today means still on time.
        $this->rule('workflow.assignment.overdue');
        $this->overdueAssignment(now()->toDateString());

        $this->artisan('opes:remind-actions')->assertSuccessful();

        $this->assertSame(0, NotificationDelivery::query()->withoutGlobalScopes()->count());
    }

    public function test_nothing_due_sends_nothing(): void
    {
        $this->rule('workflow.assignment.overdue');
        $this->rule('document.expiring');
        $this->rule('document.signature.overdue');

        $this->artisan('opes:remind-actions')->assertSuccessful();

        $this->assertSame(0, NotificationDelivery::query()->withoutGlobalScopes()->count());
    }

    public function test_a_document_inside_its_expiry_window_is_announced(): void
    {
        $this->rule('document.expiring');
        $this->document(['status' => 'issued', 'issued_at' => now(), 'expires_on' => now()->addDays(10)->toDateString()]);
        // Outside the window: silent.
        $this->document(['expires_on' => now()->addDays(90)->toDateString()]);

        $this->artisan('opes:remind-actions')->assertSuccessful();

        $this->assertSame(1, $this->sentDeliveries('document.expiring'));
    }

    public function test_a_stale_signature_request_is_announced_and_an_answered_one_is_not(): void
    {
        $this->rule('document.signature.overdue');

        $stale = $this->document();
        BusinessDocumentSignature::create([
            'business_document_id' => $stale->id,
            'signer_name' => 'Slow Signer',
            'signer_email' => 'slow@example.com',
            'status' => 'pending',
            'order' => 1,
            'signing_token' => BusinessDocumentSignature::newSigningToken(),
            'created_at' => now()->subDays(5),
        ]);

        $answered = $this->document();
        BusinessDocumentSignature::create([
            'business_document_id' => $answered->id,
            'signer_name' => 'Prompt Signer',
            'signer_email' => 'prompt@example.com',
            'status' => 'signed',
            'order' => 1,
            'signing_token' => BusinessDocumentSignature::newSigningToken(),
            'signed_at' => now()->subDays(4),
            'created_at' => now()->subDays(5),
        ]);

        $this->artisan('opes:remind-actions')->assertSuccessful();

        $this->assertSame(1, $this->sentDeliveries('document.signature.overdue'));
    }
}
