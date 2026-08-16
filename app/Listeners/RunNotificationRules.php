<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\Company;
use App\Models\NotificationRule;
use App\Models\WorkflowStep;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\NotificationMessage;
use App\Support\NotificationRecipients;
use App\Support\WorkflowConditions;
use Throwable;

/**
 * Matches an event against the business's notification rules, and fires them.
 *
 * Deliberately a sibling of RunAutomationRules rather than a branch inside it.
 * The two answer different questions — "what should the product now do" and
 * "who should now be told" — and merging them would mean one broken rule table
 * could stop the other from running.
 *
 * The conditions half is the workflow engine's matcher, unchanged. One
 * condition language for the whole product; the second one is always the one
 * that grows an eval.
 */
class RunNotificationRules
{
    public function __construct(
        protected WorkflowConditions $conditions,
        protected NotificationRecipients $recipients,
        protected NotificationDispatcher $dispatcher,
    ) {}

    public function handle(DomainEvent $event): void
    {
        $rules = NotificationRule::query()
            ->withoutGlobalScopes()
            ->where('company_id', $event->companyId)
            ->active()
            ->listeningFor($event->name)
            ->get();

        if ($rules->isEmpty()) {
            return;
        }

        $company = Company::find($event->companyId);

        if ($company === null) {
            return;
        }

        foreach ($rules as $rule) {
            try {
                $this->fire($rule, $event, $company);
            } catch (Throwable $e) {
                /*
                 * The business event already happened. A notification rule
                 * that stops an invoice being issued is far worse than one
                 * that fails to tell somebody about it, so the failure is
                 * reported and the loop continues — one broken rule must not
                 * take the others down with it, and the emitting code must
                 * never learn that a listener failed at all.
                 */
                report($e);
            }
        }
    }

    protected function fire(NotificationRule $rule, DomainEvent $event, Company $company): void
    {
        if (! $this->matches($rule, $event)) {
            return;
        }

        $recipients = $this->recipients->resolve(
            (array) $rule->recipients,
            $company,
            $event->subject,
        );

        if ($recipients->isEmpty()) {
            /*
             * Nobody to tell is still worth stamping the rule as having run,
             * or an administrator debugging "this rule never fires" cannot
             * tell "the event never happened" from "it happened and reached
             * nobody" — and those have opposite fixes.
             */
            $rule->forceFill(['last_fired_at' => now()])->save();

            return;
        }

        $this->dispatcher->send(
            NotificationMessage::fromRule($rule, $event->name, $event->subject),
            $recipients,
        );

        $rule->forceFill(['last_fired_at' => now()])->save();
    }

    /**
     * Rule conditions are evaluated against the event's subject, using the
     * same {field, operator, value} matcher a workflow step uses.
     */
    protected function matches(NotificationRule $rule, DomainEvent $event): bool
    {
        if (empty($rule->conditions)) {
            return true;
        }

        // WorkflowConditions reads `conditions` off whatever it is given, so a
        // step-shaped stand-in keeps one matcher rather than two.
        $step = new WorkflowStep;
        $step->conditions = $rule->conditions;

        return $this->conditions->passes($step, $event->subject);
    }
}
