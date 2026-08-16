<?php

namespace Tests\Feature\Service;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\ServiceJob;
use App\Models\ServiceTicket;
use App\Services\Service\ServiceBilling;
use App\Services\Service\ServiceScheduling;
use App\Services\Service\TicketDesk;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A finished job produces an invoice through the path that already exists.
 * These tests exist mainly to prove there is no second one.
 */
class ServiceBillingTest extends ServiceTestCase
{
    protected function billing(): ServiceBilling
    {
        return app(ServiceBilling::class);
    }

    protected function ticket(): ServiceTicket
    {
        $this->policy();

        return app(TicketDesk::class)->open([
            'contact_id' => $this->customer()->id,
            'subject' => 'Air conditioning service',
            'priority' => 'normal',
        ], $this->owner);
    }

    protected function completedJob(array $attributes = []): ServiceJob
    {
        $scheduling = app(ServiceScheduling::class);

        $job = $scheduling->schedule($this->ticket(), array_merge([
            'technician_id' => $this->owner->id,
            'scheduled_for' => Carbon::parse('2026-09-15 09:00'),
            'is_billable' => true,
        ], $attributes), $this->owner);

        $scheduling->logTime($job, $this->owner, 3, [
            'worked_on' => '2026-09-15',
            'hourly_rate' => 10_000,
            'notes' => 'Serviced two split units.',
        ]);

        $scheduling->addPart($job, [
            'description' => 'Filter set',
            'quantity' => 2,
            'unit_price' => 7_500,
        ]);

        return $scheduling->complete($job->refresh(), $this->owner, 'Both units serviced.');
    }

    public function test_a_completed_job_drafts_an_ordinary_sales_invoice(): void
    {
        $invoice = $this->billing()->draft($this->completedJob(), $this->owner);

        $this->assertInstanceOf(Document::class, $invoice);
        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame(DocumentStatus::Draft, $invoice->status);
        $this->assertSame(2, $invoice->lines()->count());
    }

    /** Labour at its logged rate, parts at their price. Nothing invented. */
    public function test_the_invoice_totals_the_hours_and_the_parts(): void
    {
        $invoice = $this->billing()->draft($this->completedJob(), $this->owner);

        // 3h × 10 000 + 2 × 7 500 = 45 000.
        $this->assertSame(45_000.0, (float) $invoice->total);
    }

    public function test_the_invoice_is_addressed_to_the_ticket_customer(): void
    {
        $job = $this->completedJob();

        $invoice = $this->billing()->draft($job, $this->owner);

        $this->assertSame($job->ticket->contact_id, $invoice->contact_id);
    }

    /** The job links to the invoice. It never holds a copy of it. */
    public function test_the_job_links_to_the_invoice_rather_than_restating_it(): void
    {
        $job = $this->completedJob();

        $invoice = $this->billing()->draft($job, $this->owner);

        $this->assertSame($invoice->id, $job->refresh()->document_id);
        $this->assertTrue($job->invoice->is($invoice));
    }

    /** Once billed, the hours are history — the same rule projects already enforce. */
    public function test_billing_locks_the_time_it_billed(): void
    {
        $job = $this->completedJob();

        $this->billing()->draft($job, $this->owner);

        $this->assertTrue($job->refresh()->timeEntries->first()->isLocked());
    }

    public function test_a_job_cannot_be_billed_twice(): void
    {
        $job = $this->completedJob();

        $this->billing()->draft($job, $this->owner);

        $this->expectException(RuntimeException::class);

        $this->billing()->draft($job->refresh(), $this->owner);
    }

    public function test_an_unfinished_job_cannot_be_billed(): void
    {
        $job = app(ServiceScheduling::class)->schedule($this->ticket(), [
            'technician_id' => $this->owner->id,
            'scheduled_for' => Carbon::parse('2026-09-15 09:00'),
        ], $this->owner);

        $this->expectException(RuntimeException::class);

        $this->billing()->draft($job, $this->owner);
    }

    public function test_a_warranty_visit_is_not_billed(): void
    {
        $job = $this->completedJob(['is_billable' => false]);

        $this->expectException(RuntimeException::class);

        $this->billing()->draft($job, $this->owner);
    }

    public function test_a_job_with_nothing_chargeable_on_it_is_refused(): void
    {
        $scheduling = app(ServiceScheduling::class);

        $job = $scheduling->schedule($this->ticket(), [
            'technician_id' => $this->owner->id,
            'scheduled_for' => Carbon::parse('2026-09-15 09:00'),
        ], $this->owner);

        $scheduling->complete($job, $this->owner, 'Nothing wrong with it.');

        $this->expectException(RuntimeException::class);

        $this->billing()->draft($job->refresh(), $this->owner);
    }
}
