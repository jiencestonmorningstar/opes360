<?php

namespace App\Support;

use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whether a step applies to this particular record.
 *
 * "Under ten million a manager signs; over it, the director" is the most
 * asked-for rule in the brief, and it is expressed as data: a list of
 * {field, operator, value}, matched here.
 *
 * There is deliberately no expression language, no callable and no eval. A
 * condition is typed by an administrator into a form, and a condition typed
 * into a form must never be executable code.
 *
 * Everything unrecognised fails closed — an unknown operator, a missing
 * field, a malformed row. Skipping the step is the failure that gets
 * reported ("why did this not need approval?"); silently inserting one
 * nobody expected is the failure that does not.
 */
class WorkflowConditions
{
    /**
     * The whole vocabulary, and public so the screen that writes a condition
     * offers exactly what this class can evaluate. A second list kept in the
     * admin form would drift, and the drift would be invisible: an operator
     * this does not recognise fails closed, so the step is simply skipped and
     * the approval nobody expected to lose is never missed.
     */
    public const OPERATORS = ['>', '>=', '<', '<=', '=', '!='];

    public function passes(WorkflowStep $step, ?Model $subject): bool
    {
        $conditions = $step->conditions;

        if (empty($conditions)) {
            return true;
        }

        // A condition with nothing to evaluate against cannot be satisfied.
        if ($subject === null) {
            return false;
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition) || ! $this->matches($condition, $subject)) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>  $condition */
    protected function matches(array $condition, Model $subject): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? null;
        $expected = $condition['value'] ?? null;

        if (! is_string($field) || ! in_array($operator, self::OPERATORS, true)) {
            return false;
        }

        /*
         * getAttribute() would happily return null for a column that does not
         * exist, which is indistinguishable from a column that is null — so a
         * typo in an admin screen would read as a legitimate comparison
         * against nothing.
         */
        if (! array_key_exists($field, $subject->getAttributes())) {
            return false;
        }

        $actual = $subject->getAttribute($field);

        if (is_numeric($actual) && is_numeric($expected)) {
            $actual = (float) $actual;
            $expected = (float) $expected;
        }

        return match ($operator) {
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            '=' => $actual == $expected,
            '!=' => $actual != $expected,
        };
    }
}
