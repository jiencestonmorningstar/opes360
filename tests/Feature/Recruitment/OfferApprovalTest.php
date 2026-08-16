<?php

namespace Tests\Feature\Recruitment;

use App\Models\BusinessDocument;
use App\Models\Employee;
use RuntimeException;

/**
 * The offer's approval, proven by REAL side effects — no Event::fake, which
 * would disable the very listener under test.
 */
class OfferApprovalTest extends RecruitmentTestCase
{
    public function test_an_offer_cannot_be_accepted_before_its_workflow_approves_it(): void
    {
        $application = $this->application();
        $this->offerWorkflow();

        $offer = $this->offers()->make($application, 250_000, now()->addWeeks(2)->toDateString(), $this->owner);
        $this->offers()->submit($offer, $this->owner);

        // Nobody has approved anything yet.
        try {
            $this->offers()->accept($offer->fresh(), $this->owner);
            $this->fail('An unapproved offer was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not been approved', $e->getMessage());
        }

        $this->assertSame(0, Employee::query()->count(), 'No approval means nobody joins the payroll.');
    }

    public function test_the_hand_edited_status_column_does_not_fool_acceptance(): void
    {
        $application = $this->application();
        $this->offerWorkflow();

        $offer = $this->offers()->make($application, 250_000, now()->addWeeks(2)->toDateString(), $this->owner);

        // Somebody writes "approved" straight onto the row, engine unasked.
        $offer->forceFill(['status' => 'approved'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not been approved');

        $this->offers()->accept($offer->fresh(), $this->owner);
    }

    public function test_the_engines_verdict_lands_on_the_offer_through_the_listener(): void
    {
        $application = $this->application();

        // approvedOffer() submits and has the owner act through the engine;
        // the status flip below is the listener's doing, nothing else's.
        $offer = $this->approvedOffer($application);

        $this->assertSame('approved', $offer->status);
        $this->assertTrue($offer->isApproved());
    }

    public function test_an_offer_cannot_be_submitted_without_a_workflow(): void
    {
        $application = $this->application();

        $offer = $this->offers()->make($application, 250_000, now()->addWeeks(2)->toDateString(), $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No approval workflow');

        $this->offers()->submit($offer, $this->owner);
    }

    public function test_the_offer_letter_is_a_business_document_from_the_existing_template(): void
    {
        $application = $this->application();
        $this->offerWorkflow();

        $offer = $this->offers()->make($application, 250_000, now()->addWeeks(2)->toDateString(), $this->owner);

        $letter = $offer->letter;

        $this->assertInstanceOf(BusinessDocument::class, $letter);
        $this->assertSame('offer_letter', $letter->template);
        $this->assertStringContainsString('Jean Mballa', $letter->body);
        $this->assertStringContainsString('Delivery Driver', $letter->body);
        $this->assertStringContainsString('250,000 XAF per month', $letter->body);
        // Names somebody's salary; not for the whole office to browse.
        $this->assertSame('confidential', $letter->security);
    }

    public function test_a_declined_offer_hires_nobody_and_the_application_can_move_on(): void
    {
        $application = $this->application();
        $offer = $this->approvedOffer($application);

        $this->offers()->decline($offer, $this->owner, 'Took another job.');

        $this->assertSame('declined', $offer->fresh()->status);
        $this->assertSame(0, Employee::query()->count());

        // Still in the pipeline — rejection is a separate, deliberate act.
        $this->assertTrue($application->fresh()->isActive());
    }

    public function test_a_second_offer_needs_the_first_withdrawn(): void
    {
        $application = $this->application();
        $this->offerWorkflow();

        $this->offers()->make($application, 250_000, now()->addWeeks(2)->toDateString(), $this->owner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already an offer');

        $this->offers()->make($application->fresh(), 300_000, now()->addWeeks(2)->toDateString(), $this->owner);
    }

    public function test_filling_every_opening_closes_the_vacancy(): void
    {
        $vacancy = $this->vacancy(['openings' => 1]);
        $application = $this->application($vacancy);

        $this->offers()->accept($this->approvedOffer($application), $this->owner);

        $this->assertSame('closed', $vacancy->fresh()->status);
    }
}
