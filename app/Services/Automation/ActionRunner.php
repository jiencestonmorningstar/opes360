<?php

namespace App\Services\Automation;

use App\Events\DomainEvent;
use App\Models\AutomationRule;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\User;
use App\Models\Workflow;
use App\Services\DocumentComposer;
use App\Services\Documents\DocumentLinker;
use App\Services\Documents\RecordDocumentComposer;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationMessage;
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
            // §5 item 4 of the Documents completion plan: "when X happens,
            // draft template Y" — through DocumentComposer, the same single
            // path every other creation route in this file already uses.
            'compose_document' => $this->composeDocument($event, $config),
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

    /**
     * "When X happens, draft template Y" — a document composed from the
     * event's own subject.
     *
     * Field context is resolved the same way an ERP-record "new document"
     * would resolve it (RecordDocumentComposer): a subject Documents already
     * knows how to talk about — a Contract, say — seeds `{{ contract.* }}`
     * automatically; anything else composes with company-only context rather
     * than failing the rule, since "no context available" is the normal case
     * for most triggering records, not an error.
     *
     * Deliberately produces a draft. An automation that also issued the
     * document would need a user to attribute the issue to, which an
     * automated trigger does not reliably have — see startWorkflow()'s
     * submitter requirement for the same reason stated the other way round.
     *
     * @param  array<string, mixed>  $config
     */
    protected function composeDocument(DomainEvent $event, array $config): void
    {
        $templateKey = $config['template'] ?? null;

        if (! is_string($templateKey) || $templateKey === '') {
            throw new RuntimeException('A compose_document action needs a template key.');
        }

        $company = Company::find($event->companyId);

        if ($company === null) {
            throw new RuntimeException('A compose_document action needs a current company.');
        }

        $recordComposer = app(RecordDocumentComposer::class);
        $subject = $event->subject;
        $contextKey = $recordComposer->contextKeyFor($subject);
        $context = $contextKey !== null ? [$contextKey => $subject] : [];

        $user = $event->actorId !== null ? User::find($event->actorId) : null;

        $document = BusinessDocument::create([
            'template' => $templateKey,
            'title' => (string) ($config['title'] ?? $templateKey),
            'fields' => [],
            'body' => app(DocumentComposer::class)->merge($templateKey, [], $company, $context),
            'status' => 'draft',
            'created_by' => $user?->id,
        ]);

        app(DocumentLinker::class)->attach($document, $subject, $config['link_role'] ?? 'about', $user);
    }

    /**
     * Tell somebody.
     *
     * Routed through the notification dispatcher rather than calling notify()
     * here, so an automation obeys the same mutes, quiet hours, digests and
     * deduplication as a notification rule — and lands in the same delivery
     * log. Without that, somebody who switched a category off would still be
     * interrupted by an automation saying the same thing, and the notification
     * settings screen would be quietly lying about what it controls.
     *
     * @param  array<string, mixed>  $config
     */
    protected function notify(DomainEvent $event, string $action, array $config): void
    {
        $recipients = $action === 'notify_user'
            ? User::query()->whereKey($config['user_id'] ?? null)->get()
            : app(WorkflowApprovers::class)->holdingRole($config['role'] ?? null, $event->companyId);

        if ($recipients->isEmpty()) {
            return;
        }

        app(NotificationDispatcher::class)->send(
            new NotificationMessage(
                companyId: $event->companyId,
                event: $event->name,
                // Its own category, so "stop my own rules shouting at me" is a
                // switch a person can throw without losing the product's alerts.
                category: 'automation',
                severity: 'normal',
                title: (string) ($config['message'] ?? $event->name),
                subjectType: $event->subject->getMorphClass(),
                subjectId: $event->subject->getKey(),
                channels: ['in_app', 'email'],
            ),
            $recipients,
        );
    }
}
