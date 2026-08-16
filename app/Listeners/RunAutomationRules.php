<?php

namespace App\Listeners;

use App\Events\DomainEvent;
use App\Models\AutomationRule;
use App\Services\Automation\ActionRunner;
use App\Support\WorkflowConditions;
use Throwable;

/**
 * Matches an event against the business's rules, and fires them.
 *
 * The conditions half is the workflow engine's matcher, unchanged. One
 * condition language for the whole product; the second one is always the one
 * that grows an eval.
 */
class RunAutomationRules
{
    public function __construct(
        protected WorkflowConditions $conditions,
        protected ActionRunner $runner,
    ) {}

    public function handle(DomainEvent $event): void
    {
        $rules = AutomationRule::query()
            ->withoutGlobalScopes()
            ->where('company_id', $event->companyId)
            ->active()
            ->listeningFor($event->name)
            ->get();

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $event)) {
                continue;
            }

            try {
                $this->runner->run($rule, $event);
            } catch (Throwable $e) {
                /*
                 * The business event already happened. An automation that
                 * stops an invoice being issued is worse than an automation
                 * that does not run, so the failure is reported and the loop
                 * continues — one broken rule must not take the others down
                 * with it, and the emitting code must never learn that a
                 * listener failed at all.
                 */
                report($e);
            }
        }
    }

    /**
     * Rule conditions are evaluated against the event's subject, using the
     * same {field, operator, value} matcher a workflow step uses.
     */
    protected function matches(AutomationRule $rule, DomainEvent $event): bool
    {
        if (empty($rule->conditions)) {
            return true;
        }

        // WorkflowConditions reads `conditions` off whatever it is given, so a
        // step-shaped stand-in keeps one matcher rather than two.
        $step = new \App\Models\WorkflowStep;
        $step->conditions = $rule->conditions;

        return $this->conditions->passes($step, $event->subject);
    }
}
