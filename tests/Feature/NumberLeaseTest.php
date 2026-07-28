<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Device;
use App\Models\Document;
use App\Models\NumberLease;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentNumbers;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Device number leasing — what makes an offline invoice possible.
 *
 * The rule that drives all of this: a document numbered on a phone with no
 * signal has already been printed and handed over. The server may reject it,
 * but it may never quietly renumber it, because then the business's copy and
 * the customer's copy disagree about which document this is.
 */
class NumberLeaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($this->company, $this->user);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        $this->contact = Contact::create(['name' => 'A Customer']);
    }

    protected function device(string $name = 'Kiosk phone'): Device
    {
        return Device::create([
            'user_id' => $this->user->id,
            'name' => $name,
            'platform' => 'test',
            'token_hash' => hash('sha256', Str::uuid()->toString()),
        ]);
    }

    protected function numbers(): DocumentNumbers
    {
        return app(DocumentNumbers::class);
    }

    public function test_a_device_gets_a_block_it_can_use_offline(): void
    {
        $lease = $this->numbers()->leaseFor($this->device(), 'invoice', 25);

        $this->assertSame(25, $lease->remaining());
        $this->assertSame('active', $lease->status);
        $this->assertNotNull($lease->expires_at);
    }

    public function test_asking_again_returns_the_block_the_device_already_holds(): void
    {
        $device = $this->device();

        $first = $this->numbers()->leaseFor($device, 'invoice', 25);
        $second = $this->numbers()->leaseFor($device, 'invoice', 25);

        // A second disjoint range would let the device consume both at once and
        // hand out numbers in a jumbled order.
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, NumberLease::where('device_id', $device->id)->count());
    }

    public function test_two_devices_never_share_a_number(): void
    {
        $a = $this->numbers()->leaseFor($this->device('Phone A'), 'invoice', 10);
        $b = $this->numbers()->leaseFor($this->device('Phone B'), 'invoice', 10);

        $this->assertGreaterThan($a->range_end, $b->range_start);
    }

    public function test_the_server_pool_starts_after_every_device_block(): void
    {
        $lease = $this->numbers()->leaseFor($this->device(), 'invoice', 10);
        $year = now()->format('Y');

        $number = $this->numbers()->next(DocumentType::Invoice);

        // The server must not walk into numbers a device is holding offline.
        $this->assertSame(sprintf('INV-%s-%05d', $year, $lease->range_end + 1), $number);
    }

    public function test_an_exhausted_block_is_replaced_and_the_unused_tail_recorded(): void
    {
        $device = $this->device();
        $first = $this->numbers()->leaseFor($device, 'invoice', 3);

        // The device used one number, then came back for more.
        $first->forceFill(['next_available' => $first->range_end + 1, 'status' => 'active'])->save();

        $second = $this->numbers()->leaseFor($device, 'invoice', 5);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('exhausted', $first->fresh()->status);
    }

    public function test_a_number_inside_the_lease_is_honoured(): void
    {
        $device = $this->device();
        $lease = $this->numbers()->leaseFor($device, 'invoice', 25);
        $number = $this->numbers()->format('invoice', (int) $lease->year, (int) $lease->range_start);

        $claimed = $this->numbers()->claim($number, 'invoice', $device);

        $this->assertSame((int) $lease->range_start, $claimed);
        // Consumption is recorded, so the same number cannot be issued twice.
        $this->assertSame((int) $lease->range_start + 1, (int) $lease->fresh()->next_available);
    }

    public function test_a_number_outside_the_lease_is_refused(): void
    {
        $device = $this->device();
        $lease = $this->numbers()->leaseFor($device, 'invoice', 5);
        $beyond = $this->numbers()->format('invoice', (int) $lease->year, (int) $lease->range_end + 50);

        $this->expectException(RuntimeException::class);
        $this->numbers()->claim($beyond, 'invoice', $device);
    }

    public function test_one_device_cannot_claim_another_devices_number(): void
    {
        $mine = $this->device('Phone A');
        $theirs = $this->device('Phone B');

        $theirLease = $this->numbers()->leaseFor($theirs, 'invoice', 10);
        $number = $this->numbers()->format('invoice', (int) $theirLease->year, (int) $theirLease->range_start);

        $this->expectException(RuntimeException::class);
        $this->numbers()->claim($number, 'invoice', $mine);
    }

    public function test_claiming_never_rewinds_the_cursor(): void
    {
        $device = $this->device();
        $lease = $this->numbers()->leaseFor($device, 'invoice', 25);
        $year = (int) $lease->year;
        $start = (int) $lease->range_start;

        // A device offline for a while can report its numbers out of order.
        $this->numbers()->claim($this->numbers()->format('invoice', $year, $start + 3), 'invoice', $device);
        $this->numbers()->claim($this->numbers()->format('invoice', $year, $start), 'invoice', $device);

        $this->assertSame($start + 4, (int) $lease->fresh()->next_available);
    }

    public function test_a_malformed_number_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->numbers()->claim('not-a-number', 'invoice', $this->device());
    }

    // ---- through the API -------------------------------------------------

    public function test_the_lease_endpoint_returns_everything_the_client_needs(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/sync/v1/lease', ['type' => 'invoice', 'size' => 20]);

        $response->assertOk()->assertJsonStructure([
            'lease_id', 'type', 'prefix', 'year',
            'range_start', 'range_end', 'next_available', 'remaining', 'expires_at',
        ]);

        $this->assertSame('INV', $response->json('prefix'));
        $this->assertSame(20, $response->json('remaining'));
    }

    public function test_leasing_takes_the_right_to_issue(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        // Leasing numbers is issuing them in advance; a Cashier cannot issue.
        $this->actingAs($cashier)
            ->postJson('/api/sync/v1/lease', ['type' => 'invoice'])
            ->assertForbidden();
    }

    public function test_an_offline_invoice_keeps_the_number_printed_on_the_customers_copy(): void
    {
        $device = $this->device();

        $lease = $this->numbers()->leaseFor($device, 'invoice', 25);
        $printed = $this->numbers()->format('invoice', (int) $lease->year, (int) $lease->range_start);

        $entityId = (string) Str::ulid();

        $response = $this->actingAs($this->user)->postJson('/api/sync/v1/push', [
            'device_id' => $device->id,
            'envelopes' => [[
                'id' => (string) Str::ulid(),
                'entity_type' => 'document',
                'entity_id' => $entityId,
                'operation' => 'create',
                'assigned_number' => $printed,
                'payload' => [
                    'type' => DocumentType::Invoice->value,
                    'contact_id' => $this->contact->id,
                    'status' => DocumentStatus::Issued->value,
                    'issue_date' => now()->toDateString(),
                    'currency' => 'USD',
                    'subtotal' => 250,
                    'total' => 250,
                    'balance' => 250,
                ],
                'lines' => [[
                    'description' => 'Roadside repair',
                    'quantity' => 1,
                    'unit_price' => 250,
                    'line_total' => 250,
                ]],
            ]],
        ]);

        $response->assertOk();
        $this->assertSame('applied', $response->json('results.0.status'));
        $this->assertSame($printed, $response->json('results.0.assigned_number'));

        $document = Document::find($entityId);

        $this->assertSame($printed, $document->number);
        $this->assertSame(DocumentStatus::Issued, $document->status);
        // It is a fully real issued document: hashed, tokenised, verifiable.
        $this->assertNotNull($document->content_hash);
        $this->assertNotNull($document->verification_token_id);
        $this->assertFalse($document->isTampered());
    }

    public function test_a_number_the_device_never_leased_is_rejected_not_renumbered(): void
    {
        $device = $this->device();
        $this->numbers()->leaseFor($device, 'invoice', 5);

        $entityId = (string) Str::ulid();

        $response = $this->actingAs($this->user)->postJson('/api/sync/v1/push', [
            'device_id' => $device->id,
            'envelopes' => [[
                'id' => (string) Str::ulid(),
                'entity_type' => 'document',
                'entity_id' => $entityId,
                'operation' => 'create',
                'assigned_number' => 'INV-'.now()->format('Y').'-99999',
                'payload' => [
                    'type' => DocumentType::Invoice->value,
                    'contact_id' => $this->contact->id,
                    'status' => DocumentStatus::Issued->value,
                    'issue_date' => now()->toDateString(),
                    'currency' => 'USD',
                    'subtotal' => 10,
                    'total' => 10,
                    'balance' => 10,
                ],
            ]],
        ]);

        $response->assertOk();
        $this->assertSame('rejected', $response->json('results.0.status'));

        // Rejected outright: a silently renumbered document would leave the
        // business and the customer holding different papers.
        $this->assertNull(Document::find($entityId));
    }
}
