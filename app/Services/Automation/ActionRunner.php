<?php

namespace App\Services\Automation;

use App\Events\DomainEvent;
use App\Models\AutomationRule;
use App\Models\User;
use App\Models\Workflow;
use App\Notifications\AutomationNotification;
use App\Services\WebhookDispatcher;
use App\Services\Workflow\WorkflowEngine;
use App\Support\WorkflowApprovers;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Performs one rule's action.
 *
 * Each branch delegates to something that already exists — the workflow
 * engine, the notification layer, the webhook dispatcher. Nothing here
 * implements a capability of its own, because an automation runner that starts
 * doing work itself is how a product ends up with two of everything.
 */
class ActionRunner
{
    public function __construct(
        protected WorkflowEngine $engine,
        protected WebhookDispatcher $webhooks,
    ) {}

    public function run(AutomationRule $rule, DomainEvent $event): void
    {
        $config = $rule->action_config ?? [];

        match ($rule->action) {
            'start_workflow' => $this->startWorkflow($event, $config),
            'send_webhook' => $this->sendWebhook($event),
            'set_field' => $this->setField($event->subject, $config),
            'notify_user', 'notify_role' => $this->notify($event, $rule->action, $config),
            default => throw new RuntimeException("Unknown automation action [{$rule->action}]."),
        };

        $rule->forceFill(['last_fired_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $config */
    protected function startWorkflow(DomainEvent $event, array $config): void
    {
        $workflow = isset($config['workflow_id'])
            ? Workflow::find($config['workflow_id'])
            : Workflow::defaultFor($event->subject->getMorphClass());

        if ($workflow === null) {
            throw new RuntimeException('No workflow to start for '.$event->subject->getMorphClass());
        }

        $submitter = $event->actorId !== null ? User::find($event->actorId) : null;

        if ($submitter === null) {
            throw new RuntimeException('An approval needs a submitter to attribute it to.');
        }

        $this->engine->start($event->subject, $workflow, $submitter);
    }

    protected function sendWebhook(DomainEvent $event): void
    {
        /*
         * The existing dispatcher, with the existing endpoints, secrets and
         * delivery log — §33 and §59: never a second delivery path.
         *
         * It ignores any event name not in WebhookEvents, so a domain event
         * with no matching webhook event is a quiet no-op rather than an
         * error. That is the right way round: an automation must not fail
         * because a subscriber does not exist.
         */
        $this->webhooks->send($event->name, $event->payload());
    }

    /**
     * Set one field on the record the event was about.
     *
     * The model decides what may be set, via `automatableFields()`. Without
     * that, configuration would have become a way to edit any row in the
     * product — a rule could set `status` on an invoice, or `total`, or
     * anybody's `owner_id`.
     *
     * A model that names nothing cannot be written to by automation at all,
     * which is the right default: being listenable should not imply being
     * writable.
     *
     * @param  array<string, mixed>  $config
     */
    protected function setField(Model $subject, array $config): void
    {
        $field = $config['field'] ?? null;

        $allowed = method_exists($subject, 'automatableFields')
            ? $subject->automatableFields()
            : [];

        if (! is_string($field) || ! in_array($field, $allowed, true)) {
            throw new RuntimeException(sprintf(
                'An automation may not set [%s] on %s.',
                is_string($field) ? $field : 'that',
                class_basename($subject),
            ));
        }

        $subject->forceFill([$field => $config['value'] ?? null])->save();
    }

    /** @param  array<string, mixed>  $config */
    protected function notify(DomainEvent $event, string $action, array $config): void
    {
        $recipients = $action === 'notify_user'
            ? User::query()->whereKey($config['user_id'] ?? null)->get()
            : app(WorkflowApprovers::class)->holdingRole($config['role'] ?? null, $event->companyId);

        foreach ($recipients as $recipient) {
            $recipient->notify(new AutomationNotification(
                $config['message'] ?? $event->name,
                $event->name,
            ));
        }
    }
}
