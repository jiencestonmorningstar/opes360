<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;
use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns "the Finance manager" into a list of people, now.
 *
 * Resolution happens when the rule fires, never when it is written — the same
 * reasoning as WorkflowApprovers, and the same code. A rule storing a user id
 * is wrong the day that person leaves, and nobody finds out until the alert
 * that should have stopped a duplicate payment went to a disabled mailbox.
 *
 * Everything except `permission` is delegated to WorkflowApprovers rather than
 * reimplemented. Two answers to "who holds this role" would eventually
 * disagree about whether a suspended member counts, and the disagreement would
 * show up as a notification going to someone who had been removed.
 */
class NotificationRecipients
{
    public function __construct(protected WorkflowApprovers $approvers) {}

    /**
     * @param  array<int, array<string, mixed>>  $specs
     * @return Collection<int, User>
     */
    public function resolve(array $specs, Company $company, ?Model $subject): Collection
    {
        /*
         * WorkflowApprovers reads the company from CurrentCompany, and a
         * domain event can be raised from a console command or a queued job
         * where nothing has set one. Borrowing it for the length of this call
         * — and putting back whatever was there, even on failure — keeps that
         * resolver unchanged rather than growing a second signature for our
         * benefit.
         */
        $current = app(CurrentCompany::class);
        $previous = $current->get();
        $current->set($company);

        try {
            $people = collect($specs)
                ->flatMap(fn ($spec) => is_array($spec)
                    ? $this->resolveOne($spec, $company, $subject)
                    : collect());
        } finally {
            $current->set($previous);
        }

        // The same person named twice is one person. Two alerts saying the
        // same thing is the fastest way to teach somebody to ignore both.
        return $people->unique->getKey()->values();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return Collection<int, User>
     */
    protected function resolveOne(array $spec, Company $company, ?Model $subject): Collection
    {
        $mode = $spec['mode'] ?? null;
        $value = $spec['value'] ?? null;

        /*
         * Permission is ours because it is a notification idea, not an
         * approval one: "everyone who can see stock" is a sensible audience
         * for an alert and a nonsensical one for a signature.
         */
        if ($mode === 'permission') {
            return is_string($value)
                ? NotifyCompany::recipients($company, $value)
                : collect();
        }

        // A step-shaped stand-in, so the approver resolver stays untouched.
        $step = new WorkflowStep;
        $step->approver_mode = is_string($mode) ? $mode : '';
        $step->approver_user_id = $mode === 'user' ? $value : null;
        $step->approver_role = $mode === 'role' && is_string($value) ? $value : null;
        $step->approver_department_id = $mode === 'department' ? $value : null;

        /*
         * `subject` is optional because not every rule is about a record — but
         * `creator` and `manager` are questions about one, so with nothing to
         * ask they resolve to nobody rather than falling back to the owner. A
         * rule that quietly redirects to whoever is available is worse than
         * one that reaches nobody: the second gets noticed and fixed.
         */
        if ($subject === null && in_array($mode, ['creator', 'manager'], true)) {
            return collect();
        }

        return $this->approvers->resolve($step, $subject ?? new WorkflowStep);
    }
}
