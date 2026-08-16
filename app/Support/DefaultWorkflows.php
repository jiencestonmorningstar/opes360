<?php

namespace App\Support;

use App\Models\Company;
use App\Models\ComplianceFiling;
use App\Models\Contract;
use App\Models\ExpenseClaim;
use App\Models\PurchaseRequisition;
use App\Models\ServiceJob;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;

/**
 * The approval paths a business starts with.
 *
 * The engine is deliberately data-driven: it knows nothing about requisitions
 * or claims, only about steps and who to ask. That is the right design and it
 * has one consequence nobody noticed until the screens went in — a business
 * with no workflow rows cannot submit anything at all. Every `submit` refuses
 * with "no approval path is defined", which is a correct refusal and a dead
 * end, because there is no screen yet on which to define one.
 *
 * So a new business gets these. They are ordinary rows with nothing special
 * about them: editable, deactivatable, and replaceable by whatever the
 * business actually does. What matters is that the first person to raise a
 * requisition gets it approved rather than getting an explanation.
 *
 * The shape of each is the same and it is the conservative one: ask the owner.
 * A business that wants two steps, or a threshold, or a department head, can
 * say so — but guessing at a structure nobody asked for and then having
 * approvals appear from unexpected people is worse than asking the one person
 * who certainly has the authority.
 */
class DefaultWorkflows
{
    /**
     * What "substantial" means, per currency.
     *
     * One figure cannot serve both: 500 000 XAF is a van, 500 000 USD is a
     * building. These are round numbers in the middle of what a small
     * business would want a second pair of eyes on — roughly a month of a
     * modest wage bill — and every one of them is a guess that the business
     * should correct on the workflow screen the first week it uses this.
     *
     * The guess is worth making anyway. A seeded threshold that is somewhat
     * wrong gets noticed and adjusted; no threshold at all means the owner
     * approves every box of pens until they turn the workflow off entirely,
     * and then nothing is approved by anybody.
     */
    protected const THRESHOLDS = [
        'XAF' => 500000,
        'XOF' => 500000,
        'NGN' => 500000,
        'GHS' => 10000,
        'KES' => 100000,
        'ZAR' => 15000,
        'USD' => 1000,
        'EUR' => 1000,
        'GBP' => 1000,
    ];

    /** What a currency we have never seen gets. Deliberately low: being asked
     *  once too often is a nuisance, and not being asked is the failure. */
    protected const FALLBACK_THRESHOLD = 1000;

    /**
     * What each subject gets, and why anybody is asked at all.
     *
     * @return array<class-string, array{name: string, step: string, threshold_on?: string}>
     */
    protected static function catalogue(): array
    {
        return [
            // Somebody spent their own money and wants it back. The owner is
            // the one who decides whether the business owes it.
            ExpenseClaim::class => [
                'name' => 'Staff expense claims',
                'step' => 'Owner approves',
            ],
            /*
             * Asking to buy something — the one seeded path with a threshold
             * on it, because "small things go through, big things get looked
             * at" is what every business already does informally and a
             * requisition for a box of pens should not need the owner.
             *
             * The step is skipped when the estimate is under the figure, and
             * a workflow whose every step is skipped finishes approved — see
             * WorkflowEngine::advance(). So below the line the requisition
             * simply goes through, with the submission still on the record.
             */
            PurchaseRequisition::class => [
                'name' => 'Purchase requisitions',
                'step' => 'Owner approves anything substantial',
                'threshold_on' => 'estimated_total',
            ],
            // Signing the business up to something. Always worth a signature,
            // whatever it is worth.
            Contract::class => [
                'name' => 'Contracts',
                'step' => 'Owner approves',
            ],
            // Swearing that a statutory return went in. The person who files
            // it should not also be the only person who says it was filed.
            ComplianceFiling::class => [
                'name' => 'Statutory filings',
                'step' => 'Owner approves',
            ],
            // Work carried out at a customer's site, before it is billed.
            ServiceJob::class => [
                'name' => 'Service visits',
                'step' => 'Owner approves',
            ],
        ];
    }

    /**
     * Give a business the paths it does not have.
     *
     * Safe to run more than once: anything already defined for a subject is
     * left exactly as it is. A business that has replaced the seeded path with
     * its own must never have it quietly reinstated underneath them.
     */
    public static function seed(Company $company): void
    {
        DB::transaction(function () use ($company) {
            foreach (static::catalogue() as $subjectType => $spec) {
                $exists = Workflow::withoutGlobalScopes()
                    ->where('company_id', $company->id)
                    ->where('subject_type', $subjectType)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $workflow = Workflow::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'subject_type' => $subjectType,
                    'name' => $spec['name'],
                    'is_active' => true,
                    'is_default' => true,
                ]);

                WorkflowStep::withoutGlobalScopes()->create([
                    'company_id' => $company->id,
                    'workflow_id' => $workflow->id,
                    'position' => 1,
                    'name' => $spec['step'],
                    'type' => 'approval',
                    /*
                     * `owner`, not a named user and not the Owner role. The
                     * engine resolves it when the step is reached, so it
                     * survives the business changing hands — a workflow that
                     * named a person is wrong the day they leave, and nobody
                     * finds out until something has sat unapproved for a week.
                     */
                    'approver_mode' => 'owner',
                    'quorum' => 'any',
                    'conditions' => isset($spec['threshold_on'])
                        ? [[
                            'field' => $spec['threshold_on'],
                            'operator' => '>',
                            'value' => static::thresholdFor($company),
                        ]]
                        : null,
                ]);
            }
        });
    }

    /** The subject types a business is given a path for. */
    public static function subjects(): array
    {
        return array_keys(static::catalogue());
    }

    /** What counts as substantial in this business's own money. */
    public static function thresholdFor(Company $company): int
    {
        return static::THRESHOLDS[$company->currency] ?? static::FALLBACK_THRESHOLD;
    }
}
