<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Device;
use App\Models\Document;
use App\Models\NumberLease;
use App\Models\Payment;
use App\Models\Receipt;
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

    public function test_an_abandoned_lease_is_closed_with_its_gap_recorded(): void
    {
        $device = $this->device();
        $lease = $this->numbers()->leaseFor($device, 'invoice', 10);

        // The device used two numbers, then was lost.
        $this->numbers()->claim(
            $this->numbers()->format('invoice', (int) $lease->year, (int) $lease->range_start + 1),
            'invoice',
            $device,
        );

        $lease->forceFill(['expires_at' => now()->subDay()])->save();

        $this->artisan('opes:expire-leases')->assertSuccessful();

        $lease->refresh();

        $this->assertSame('expired', $lease->status);
        // Every gap in the sequence needs a dated row explaining it — this is
        // the answer to "what happened to invoices 3 through 10".
        $this->assertSame((int) $lease->range_start + 2, (int) $lease->void_unused_from);
    }

    public function test_a_fully_used_lease_records_no_phantom_gap(): void
    {
        $device = $this->device();
        $lease = $this->numbers()->leaseFor($device, 'invoice', 2);

        $lease->forceFill([
            'next_available' => $lease->range_end + 1,
            'expires_at' => now()->subDay(),
        ])->save();

        $this->artisan('opes:expire-leases')->assertSuccessful();

        $this->assertNull($lease->fresh()->void_unused_from);
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $lease = $this->numbers()->leaseFor($this->device(), 'invoice', 5);
        $lease->forceFill(['expires_at' => now()->subDay()])->save();

        $this->artisan('opes:expire-leases --dry-run')->assertSuccessful();

        $this->assertSame('active', $lease->fresh()->status);
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

    public function test_a_payment_taken_offline_settles_the_invoice_and_keeps_its_receipt_number(): void
    {
        $device = $this->device();
        $lease = $this->numbers()->leaseFor($device, 'receipt', 25);
        $printed = $this->numbers()->format('receipt', (int) $lease->year, (int) $lease->range_start);

        $invoice = Document::create([
            'type' => DocumentType::Invoice,
            'number' => 'INV-TEST-1',
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Issued,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 300,
            'total' => 300,
            'amount_paid' => 0,
            'balance' => 300,
        ]);

        $paymentId = (string) Str::ulid();

        $response = $this->actingAs($this->user)->postJson('/api/sync/v1/push', [
            'device_id' => $device->id,
            'envelopes' => [[
                'id' => (string) Str::ulid(),
                'entity_type' => 'payment',
                'entity_id' => $paymentId,
                'operation' => 'create',
                'assigned_number' => $printed,
                'payload' => [
                    'document_id' => $invoice->id,
                    'amount' => 300,
                    'method' => 'cash',
                    'reference' => 'Market stall',
                ],
            ]],
        ]);

        $response->assertOk();
        $this->assertSame('applied', $response->json('results.0.status'));

        // A payment is a transaction, not a row: the invoice must actually move.
        $invoice->refresh();
        $this->assertSame(DocumentStatus::Paid, $invoice->status);
        $this->assertSame('300.00', $invoice->amount_paid);
        $this->assertSame('0.00', $invoice->balance);

        $receipt = Receipt::firstOrFail();

        $this->assertSame($printed, $receipt->number);
        $this->assertNotNull($receipt->verification_token_id);
        $this->assertFalse($receipt->isTampered());
    }

    public function test_a_payment_the_world_has_overtaken_is_reported_as_a_conflict(): void
    {
        $device = $this->device();
        $lease = $this->numbers()->leaseFor($device, 'receipt', 25);

        $invoice = Document::create([
            'type' => DocumentType::Invoice,
            'number' => 'INV-TEST-2',
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Paid,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 100,
            'total' => 100,
            // Another device already settled it while this one was offline.
            'amount_paid' => 100,
            'balance' => 0,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/sync/v1/push', [
            'device_id' => $device->id,
            'envelopes' => [[
                'id' => (string) Str::ulid(),
                'entity_type' => 'payment',
                'entity_id' => (string) Str::ulid(),
                'operation' => 'create',
                'assigned_number' => $this->numbers()->format('receipt', (int) $lease->year, (int) $lease->range_start),
                'payload' => ['document_id' => $invoice->id, 'amount' => 100, 'method' => 'cash'],
            ]],
        ]);

        $response->assertOk();

        // Surfaced for a person to resolve — refund or credit — never forced
        // through into a negative balance.
        $this->assertSame('conflict', $response->json('results.0.status'));
        $this->assertSame('100.00', $invoice->fresh()->amount_paid);
    }

    public function test_replaying_a_payment_does_not_take_the_money_twice(): void
    {
        $device = $this->device();
        $this->numbers()->leaseFor($device, 'receipt', 25);

        $invoice = Document::create([
            'type' => DocumentType::Invoice,
            'number' => 'INV-TEST-3',
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Issued,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 200,
            'total' => 200,
            'amount_paid' => 0,
            'balance' => 200,
        ]);

        $envelope = [
            'id' => (string) Str::ulid(),
            'entity_type' => 'payment',
            'entity_id' => (string) Str::ulid(),
            'operation' => 'create',
            'payload' => ['document_id' => $invoice->id, 'amount' => 120, 'method' => 'cash'],
        ];

        $this->actingAs($this->user)
            ->postJson('/api/sync/v1/push', ['device_id' => $device->id, 'envelopes' => [$envelope]])
            ->assertOk();

        // The same envelope again, as a device retrying after a lost response.
        $second = $this->actingAs($this->user)
            ->postJson('/api/sync/v1/push', ['device_id' => $device->id, 'envelopes' => [$envelope]]);

        $this->assertSame('duplicate', $second->json('results.0.status'));
        $this->assertSame('120.00', $invoice->fresh()->amount_paid);
        $this->assertSame(1, Payment::count());
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
