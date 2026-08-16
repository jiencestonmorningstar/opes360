<?php

namespace Tests\Feature\Service;

use App\Models\AssetMaintenance;
use App\Models\FixedAsset;
use App\Models\Item;
use App\Models\Project;
use App\Models\ProjectTimeEntry;
use App\Models\Role;
use App\Models\ServiceJob;
use App\Models\ServiceTicket;
use App\Services\Service\ServiceScheduling;
use App\Services\Service\TicketDesk;
use Illuminate\Support\Carbon;
use RuntimeException;

class ServiceJobTest extends ServiceTestCase
{
    protected function scheduling(): ServiceScheduling
    {
        return app(ServiceScheduling::class);
    }

    protected function ticket(array $attributes = []): ServiceTicket
    {
        $this->policy();

        return app(TicketDesk::class)->open(array_merge([
            'contact_id' => $this->customer()->id,
            'subject' => 'Generator will not start',
            'priority' => 'high',
        ], $attributes), $this->owner);
    }

    protected function job(ServiceTicket $ticket, array $attributes = []): ServiceJob
    {
        return $this->scheduling()->schedule($ticket, array_merge([
            'technician_id' => $this->owner->id,
            'scheduled_for' => Carbon::parse('2026-09-15 09:00'),
        ], $attributes), $this->owner);
    }

    protected function asset(): FixedAsset
    {
        return FixedAsset::create([
            'name' => 'Standby generator',
            'category' => 'equipment',
            'cost' => 4_000_000,
            'residual_value' => 0,
            'useful_life_months' => 120,
            'method' => 'straight_line',
            'acquired_on' => '2025-01-01',
            'status' => 'active',
        ]);
    }

    public function test_a_job_hangs_off_the_ticket_that_caused_it(): void
    {
        $ticket = $this->ticket();
        $job = $this->job($ticket);

        $this->assertTrue($job->ticket->is($ticket));
        $this->assertTrue($ticket->jobs->first()->is($job));
        $this->assertSame('scheduled', $job->status);
    }

    /** The customer lives on the ticket. A visit does not get a second copy of who it is for. */
    public function test_a_job_reads_its_customer_through_the_ticket(): void
    {
        $job = $this->job($ticket = $this->ticket());

        $this->assertTrue($job->customer()->is($ticket->contact));
    }

    public function test_a_visit_records_what_was_found_on_site(): void
    {
        $job = $this->scheduling()->complete(
            $this->job($this->ticket()),
            $this->owner,
            'Fuel filter blocked. Replaced and tested under load.',
        );

        $this->assertSame('completed', $job->status);
        $this->assertNotNull($job->completed_at);
        $this->assertStringContainsString('Fuel filter', $job->on_site_notes);
    }

    public function test_a_completed_job_cannot_be_completed_twice(): void
    {
        $job = $this->scheduling()->complete($this->job($this->ticket()), $this->owner, 'Done.');

        $this->expectException(RuntimeException::class);

        $this->scheduling()->complete($job, $this->owner, 'Done again.');
    }

    /*
     * The reuse rules, asserted rather than asserted-in-a-document.
     */

    /** Technician hours are ProjectTimeEntry rows. There is one timesheet in this product. */
    public function test_time_on_a_job_is_logged_on_the_existing_timesheet(): void
    {
        $technician = $this->memberAt(Role::SALES_OFFICER);
        $job = $this->job($this->ticket());

        $entry = $this->scheduling()->logTime($job, $technician, 3.5, [
            'worked_on' => '2026-09-15',
            'hourly_rate' => 10_000,
            'notes' => 'On site.',
        ]);

        $this->assertInstanceOf(ProjectTimeEntry::class, $entry);
        $this->assertSame($job->id, $entry->service_job_id);
        $this->assertNull($entry->project_id);
        $this->assertSame('3.50', (string) $entry->hours);
        $this->assertTrue($job->refresh()->timeEntries->first()->is($entry));
    }

    /** A service contract billed as a project keeps both links, and one set of hours. */
    public function test_job_time_can_also_belong_to_a_project(): void
    {
        $project = Project::create([
            'name' => 'Facilities maintenance 2026',
            'status' => 'active',
            'is_billable' => true,
            'created_by' => $this->owner->id,
        ]);

        $job = $this->job($this->ticket(['project_id' => $project->id]));

        $entry = $this->scheduling()->logTime($job, $this->owner, 2, ['worked_on' => '2026-09-15']);

        $this->assertSame($project->id, $entry->project_id);
        $this->assertSame(2.0, $project->refresh()->unbilledHours());
    }

    /** A job against equipment points at the asset register, it does not describe the machine again. */
    public function test_a_job_can_be_against_a_fixed_asset(): void
    {
        $asset = $this->asset();
        $job = $this->job($this->ticket(), ['fixed_asset_id' => $asset->id]);

        $this->assertTrue($job->asset->is($asset));
    }

    /** Maintenance is recorded once, by the module that already owns it. */
    public function test_completing_a_maintenance_job_closes_the_asset_maintenance_record(): void
    {
        $asset = $this->asset();
        $maintenance = AssetMaintenance::create([
            'fixed_asset_id' => $asset->id,
            'kind' => 'service',
            'title' => 'Annual service',
            'due_on' => '2026-09-15',
        ]);

        $job = $this->job($this->ticket(), [
            'fixed_asset_id' => $asset->id,
            'asset_maintenance_id' => $maintenance->id,
        ]);

        $this->scheduling()->complete($job, $this->owner, 'Serviced.', Carbon::parse('2026-09-15 15:00'));

        $this->assertTrue($maintenance->refresh()->isDone());
        $this->assertSame('2026-09-15', $maintenance->completed_on->toDateString());
    }

    public function test_parts_used_point_at_the_catalogue(): void
    {
        $item = Item::create([
            'name' => 'Fuel filter',
            'sku' => 'FF-100',
            'price' => 15_000,
            'cost' => 9_000,
            'type' => 'product',
        ]);

        $job = $this->job($this->ticket());

        $part = $this->scheduling()->addPart($job, [
            'item_id' => $item->id,
            'quantity' => 2,
            'unit_price' => 15_000,
        ]);

        $this->assertTrue($part->item->is($item));
        $this->assertSame('Fuel filter', $part->label());
        $this->assertSame(30_000.0, $job->refresh()->partsTotal());
    }

    /** A part fitted off the van has no catalogue row. It still has to be billable. */
    public function test_a_part_can_be_described_rather_than_catalogued(): void
    {
        $part = $this->scheduling()->addPart($this->job($this->ticket()), [
            'description' => 'Silicone hose, cut to length',
            'quantity' => 1,
            'unit_price' => 4_500,
        ]);

        $this->assertNull($part->item_id);
        $this->assertSame('Silicone hose, cut to length', $part->label());
    }
}
