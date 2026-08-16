<?php

namespace App\Services\Compliance;

use App\Models\Risk;
use App\Models\RiskControl;
use App\Models\User;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Recording risks, the controls against them, and the reviews that keep the
 * register from becoming a document nobody has read since it was written.
 *
 * The service refuses two things on purpose: a score outside the scale, and a
 * closure with no reason. Both are the sort of thing a form would let through
 * and an auditor would ask about a year later.
 */
class RiskRegister
{
    /** @param  array<string, mixed>  $attributes */
    public function record(array $attributes, ?User $actor = null): Risk
    {
        $this->assertScale($attributes['likelihood'] ?? null, 'likelihood');
        $this->assertScale($attributes['impact'] ?? null, 'impact');

        // Model::create() leaves DB defaults alone, so anything the rest of
        // the module reads back is set here rather than hoped for.
        return Risk::create(array_merge([
            'status' => 'open',
            'treatment' => 'mitigate',
            'category' => 'operational',
            'identified_on' => Carbon::today(),
            'created_by' => $actor?->id,
            'owner_id' => $attributes['owner_id'] ?? $actor?->id,
        ], $attributes));
    }

    /**
     * The residual score, as a person judged it.
     *
     * Never derived from the controls attached. A control that exists on
     * paper has reduced nothing, and a register that lowers its own numbers
     * when somebody types a mitigation into a form is producing reassurance
     * rather than information.
     */
    public function reassess(Risk $risk, int $residualLikelihood, int $residualImpact): Risk
    {
        $this->assertScale($residualLikelihood, 'likelihood');
        $this->assertScale($residualImpact, 'impact');

        $risk->forceFill([
            'residual_likelihood' => $residualLikelihood,
            'residual_impact' => $residualImpact,
        ])->save();

        return $risk->refresh();
    }

    /** @param  array<string, mixed>  $attributes */
    public function addControl(Risk $risk, array $attributes, ?User $actor = null): RiskControl
    {
        return RiskControl::create(array_merge([
            'company_id' => $risk->company_id,
            'risk_id' => $risk->id,
            'kind' => 'preventive',
            'status' => 'planned',
            'created_by' => $actor?->id,
            'owner_id' => $attributes['owner_id'] ?? $risk->owner_id,
        ], $attributes));
    }

    /**
     * A control is in place from a date, not in principle.
     *
     * Effectiveness is asked for at the same moment because it is the only
     * moment somebody actually knows: "we put this in and it does most of the
     * job" is information; a blank field a year later is not.
     */
    public function markControlInPlace(RiskControl $control, ?Carbon $on = null, ?int $effectiveness = null): RiskControl
    {
        if ($effectiveness !== null) {
            $this->assertScale($effectiveness, 'effectiveness');
        }

        $control->forceFill([
            'status' => 'in_place',
            'implemented_on' => ($on ?? Carbon::today())->copy()->startOfDay(),
            'effectiveness' => $effectiveness ?? $control->effectiveness,
        ])->save();

        return $control->refresh();
    }

    /**
     * Somebody looked at this risk again, on this date.
     *
     * The next review is counted from when the review actually happened —
     * the opposite of a statutory deadline, and for a reason worth stating:
     * a review answers "how stale is what we believe about this", and
     * staleness runs from the last time somebody looked, not from the day the
     * diary said they should have. A review done three months late genuinely
     * does buy another full interval of confidence.
     */
    public function review(Risk $risk, ?Carbon $on = null, ?string $notes = null, ?User $actor = null): Risk
    {
        if ($risk->isClosed()) {
            throw new RuntimeException('This risk is closed. Reopen it before reviewing it.');
        }

        $on = ($on ?? Carbon::today())->copy()->startOfDay();

        $risk->forceFill([
            'last_reviewed_on' => $on,
            'next_review_on' => $risk->review_interval_months
                ? $on->copy()->addMonths((int) $risk->review_interval_months)
                : null,
            'description' => $notes !== null && $notes !== ''
                ? trim($risk->description."\n\n".$on->toFormattedDateString().': '.$notes)
                : $risk->description,
        ])->save();

        $risk->emitDomainEvent('risk.reviewed', [
            'reviewed_on' => $on->toDateString(),
            'reviewed_by' => $actor?->id,
            'inherent_score' => $risk->inherentScore(),
            'residual_score' => $risk->residualScore(),
        ]);

        return $risk->refresh();
    }

    /**
     * Closing needs a reason.
     *
     * "Why is this no longer on the register" is the first question anybody
     * asks about a risk that vanished, and a closure with a blank reason
     * cannot answer it — which makes the closure itself look like a cover-up
     * when it was almost certainly just a tidy-up.
     */
    public function close(Risk $risk, string $reason, ?User $actor = null): Risk
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Closing a risk needs a reason.');
        }

        $risk->forceFill([
            'status' => 'closed',
            'closed_on' => Carbon::today(),
            'closure_reason' => trim($reason),
            'closed_by' => $actor?->id,
            // Cleared on purpose: a closed risk must not keep appearing on
            // the review list, and leaving the date behind would mean it did.
            'next_review_on' => null,
        ])->save();

        $risk->emitDomainEvent('risk.closed', ['reason' => trim($reason)]);

        return $risk->refresh();
    }

    public function reopen(Risk $risk, ?Carbon $nextReviewOn = null): Risk
    {
        $risk->forceFill([
            'status' => 'open',
            'closed_on' => null,
            'closure_reason' => null,
            'closed_by' => null,
            'next_review_on' => $nextReviewOn?->copy()->startOfDay(),
        ])->save();

        return $risk->refresh();
    }

    /**
     * A 1–5 scale, enforced.
     *
     * A 7 typed into a likelihood field does not fail — it sorts to the top
     * of the register and pushes a genuine severe risk down, silently.
     */
    protected function assertScale(mixed $value, string $what): void
    {
        if (! is_numeric($value) || (int) $value < 1 || (int) $value > 5) {
            throw new RuntimeException(ucfirst($what).' must be a whole number between 1 and 5.');
        }
    }
}
