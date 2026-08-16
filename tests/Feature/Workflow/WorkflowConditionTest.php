<?php

namespace Tests\Feature\Workflow;

use App\Support\WorkflowConditions;

class WorkflowConditionTest extends WorkflowTestCase
{
    public function test_a_step_with_no_conditions_always_applies(): void
    {
        $step = $this->workflow([['conditions' => null]])->steps->first();

        $this->assertTrue($this->conditions()->passes($step, $this->expense(50)));
    }

    public function test_an_amount_threshold_decides_whether_a_step_applies(): void
    {
        $step = $this->workflow([[
            'conditions' => [['field' => 'total', 'operator' => '>=', 'value' => 10_000_000]],
        ]])->steps->first();

        $this->assertFalse($this->conditions()->passes($step, $this->expense(9_999_999)));
        $this->assertTrue($this->conditions()->passes($step, $this->expense(10_000_000)));
        $this->assertTrue($this->conditions()->passes($step, $this->expense(50_000_000)));
    }

    public function test_every_condition_must_pass(): void
    {
        $step = $this->workflow([[
            'conditions' => [
                ['field' => 'total', 'operator' => '>=', 'value' => 1_000],
                ['field' => 'status', 'operator' => '=', 'value' => 'draft'],
            ],
        ]])->steps->first();

        $this->assertTrue($this->conditions()->passes($step, $this->expense(5_000)));
    }

    public function test_one_failing_condition_fails_the_step(): void
    {
        $step = $this->workflow([[
            'conditions' => [
                ['field' => 'total', 'operator' => '>=', 'value' => 1_000],
                ['field' => 'status', 'operator' => '=', 'value' => 'void'],
            ],
        ]])->steps->first();

        $this->assertFalse($this->conditions()->passes($step, $this->expense(5_000)));
    }

    /**
     * Fails closed: the step is skipped rather than applied. A typo in an
     * admin screen must not silently insert an approval nobody expected —
     * and skipping is the outcome that gets reported and fixed.
     */
    public function test_a_condition_on_a_field_the_subject_does_not_have_fails_closed(): void
    {
        $step = $this->workflow([[
            'conditions' => [['field' => 'not_a_column', 'operator' => '>', 'value' => 1]],
        ]])->steps->first();

        $this->assertFalse($this->conditions()->passes($step, $this->expense()));
    }

    public function test_an_unknown_operator_fails_closed(): void
    {
        $step = $this->workflow([[
            'conditions' => [['field' => 'total', 'operator' => 'DROP TABLE', 'value' => 1]],
        ]])->steps->first();

        $this->assertFalse($this->conditions()->passes($step, $this->expense()));
    }

    public function test_a_malformed_condition_row_fails_closed(): void
    {
        $step = $this->workflow([[
            'conditions' => [['nonsense' => true]],
        ]])->steps->first();

        $this->assertFalse($this->conditions()->passes($step, $this->expense()));
    }

    public function test_the_supported_operators_all_work(): void
    {
        $expense = $this->expense(100);

        $cases = [
            ['>', 99, true], ['>', 100, false],
            ['>=', 100, true], ['>=', 101, false],
            ['<', 101, true], ['<', 100, false],
            ['<=', 100, true], ['<=', 99, false],
            ['=', 100, true], ['=', 99, false],
            ['!=', 99, true], ['!=', 100, false],
        ];

        foreach ($cases as $index => [$operator, $value, $expected]) {
            $step = $this->workflow([[
                'conditions' => [['field' => 'total', 'operator' => $operator, 'value' => $value]],
            ]], ['name' => 'Workflow '.$index])->steps->first();

            $this->assertSame(
                $expected,
                $this->conditions()->passes($step, $expense),
                "total 100 {$operator} {$value}",
            );
        }
    }

    protected function conditions(): WorkflowConditions
    {
        return app(WorkflowConditions::class);
    }
}
