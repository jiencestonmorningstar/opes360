<?php

namespace Tests\Feature\Hr;

use App\Models\PerformanceReview;
use RuntimeException;

class PerformanceReviewTest extends HrTestCase
{
    public function test_a_review_belongs_to_an_employee_and_the_company(): void
    {
        $employee = $this->employee();
        $review = $this->review($employee);

        $this->assertTrue($review->employee->is($employee));
        $this->assertSame($this->company->id, $review->company_id);
        $this->assertTrue($employee->performanceReviews->first()->is($review));
    }

    public function test_a_review_records_who_wrote_it(): void
    {
        $review = $this->review($this->employee(), ['reviewer_id' => $this->owner->id]);

        $this->assertTrue($review->reviewer->is($this->owner));
    }

    /** The job the person was actually doing during the period, not the one they hold now. */
    public function test_a_review_remembers_the_position_reviewed(): void
    {
        $position = $this->position();
        $review = $this->review($this->employee(), ['position_id' => $position->id]);

        $this->assertTrue($review->position->is($position));
    }

    public function test_a_period_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->review($this->employee(), [
            'period_starts_on' => '2026-12-31',
            'period_ends_on' => '2026-01-01',
        ]);
    }

    public function test_a_rating_outside_the_scale_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->review($this->employee(), ['overall_rating' => 9]);
    }

    public function test_a_rating_on_the_scale_is_kept(): void
    {
        $review = $this->review($this->employee(), ['overall_rating' => 4]);

        $this->assertSame(4, $review->fresh()->overall_rating);
        $this->assertSame('Exceeds expectations', $review->fresh()->ratingLabel());
    }

    public function test_a_review_without_a_rating_is_valid_while_it_is_being_written(): void
    {
        $review = $this->review($this->employee());

        $this->assertNull($review->overall_rating);
        $this->assertNull($review->ratingLabel());
    }

    public function test_a_new_review_starts_as_a_draft(): void
    {
        $this->assertSame('draft', $this->review($this->employee())->status);
    }

    public function test_a_draft_can_still_be_rewritten(): void
    {
        $review = $this->review($this->employee(), ['overall_rating' => 2]);

        $review->update(['overall_rating' => 3, 'summary' => 'Reconsidered.']);

        $this->assertSame(3, $review->fresh()->overall_rating);
    }

    public function test_sharing_a_review_stamps_the_moment_it_was_shared(): void
    {
        $review = $this->review($this->employee(), ['overall_rating' => 3]);

        $review->share();

        $this->assertSame('shared', $review->fresh()->status);
        $this->assertNotNull($review->fresh()->shared_at);
    }

    public function test_a_review_cannot_be_shared_without_a_rating(): void
    {
        $this->expectException(RuntimeException::class);

        $this->review($this->employee())->share();
    }

    public function test_an_employee_acknowledges_a_shared_review(): void
    {
        $review = $this->review($this->employee(), ['overall_rating' => 3]);
        $review->share();

        $review->acknowledge('I agree with the summary.');

        $this->assertSame('acknowledged', $review->fresh()->status);
        $this->assertNotNull($review->fresh()->acknowledged_at);
        $this->assertSame('I agree with the summary.', $review->fresh()->employee_comment);
    }

    public function test_a_draft_cannot_be_acknowledged_before_the_employee_has_seen_it(): void
    {
        $this->expectException(RuntimeException::class);

        $this->review($this->employee(), ['overall_rating' => 3])->acknowledge();
    }

    /**
     * The point of the whole record.
     *
     * An acknowledgement is the employee saying "this is what I was told". If
     * the rating or the summary can still be changed afterwards, the signature
     * is attached to a document that no longer exists, and the record is worth
     * nothing in the dispute it was kept for.
     */
    public function test_an_acknowledged_review_cannot_have_its_verdict_rewritten(): void
    {
        $review = $this->review($this->employee(), ['overall_rating' => 3, 'summary' => 'Steady year.']);
        $review->share();
        $review->acknowledge();

        $this->expectException(RuntimeException::class);

        $review->fresh()->update(['overall_rating' => 1]);
    }

    public function test_an_acknowledged_review_still_accepts_the_employees_own_comment(): void
    {
        $review = $this->review($this->employee(), ['overall_rating' => 3]);
        $review->share();
        $review->acknowledge();

        $review->fresh()->update(['employee_comment' => 'Added on reflection.']);

        $this->assertSame('Added on reflection.', $review->fresh()->employee_comment);
    }

    public function test_reviews_are_listed_newest_period_first(): void
    {
        $employee = $this->employee();

        $this->review($employee, ['period_starts_on' => '2024-01-01', 'period_ends_on' => '2024-12-31']);
        $this->review($employee, ['period_starts_on' => '2026-01-01', 'period_ends_on' => '2026-12-31']);

        $this->assertSame(
            '2026-01-01',
            PerformanceReview::latestFirst()->first()->period_starts_on->toDateString()
        );
    }

    public function test_reviews_survive_the_position_being_abolished(): void
    {
        $position = $this->position();
        $review = $this->review($this->employee(), ['position_id' => $position->id]);

        $position->forceDelete();

        $this->assertNotNull($review->fresh());
        $this->assertNull($review->fresh()->position_id);
    }

    public function test_an_unknown_cycle_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->review($this->employee(), ['cycle' => 'whenever']);
    }
}
