<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Device;
use App\Models\Document;
use App\Models\Role;
use App\Models\SyncReceipt;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The offline sync protocol. These are the guarantees a market trader's evening
 * depends on: nothing duplicated, nothing lost, and a stuck record surfaced
 * rather than retried into the void.
 */
class SyncEngineTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
        ]);

        $this->company->users()->attach($this->user->id, [
            'role_id' => Role::where('slug', 'owner')->value('id'),
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function envelope(string $type, string $entityId, array $payload, array $extra = []): array
    {
        return array_merge([
            'id' => (string) Str::ulid(),
            'entity_type' => $type,
            'entity_id' => $entityId,
            'operation' => 'create',
            'payload' => $payload,
        ], $extra);
    }

    protected function push(array $envelopes)
    {
        return $this->actingAs($this->user)->postJson('/api/sync/v1/push', ['envelopes' => $envelopes]);
    }

    public function test_a_record_created_offline_lands_on_the_server(): void
    {
        $id = (string) Str::ulid();

        $this->push([$this->envelope('contact', $id, ['name' => 'Offline Customer', 'type' => 'customer'])])
            ->assertOk()
            ->assertJsonPath('results.0.status', 'applied');

        $contact = Contact::find($id);

        $this->assertNotNull($contact);
        $this->assertSame('Offline Customer', $contact->name);
        $this->assertSame($this->company->id, $contact->company_id);
    }

    public function test_replaying_the_same_envelope_does_not_create_a_second_record(): void
    {
        $envelope = $this->envelope('contact', (string) Str::ulid(), ['name' => 'Retry Customer']);

        $this->push([$envelope])->assertJsonPath('results.0.status', 'applied');

        // Exactly what a device does after a timeout it cannot interpret.
        $this->push([$envelope])->assertJsonPath('results.0.status', 'duplicate');

        $this->assertSame(1, Contact::count());
    }

    public function test_a_bad_envelope_does_not_block_the_good_ones_behind_it(): void
    {
        $results = $this->push([
            $this->envelope('contact', (string) Str::ulid(), ['name' => 'First']),
            $this->envelope('nonsense', (string) Str::ulid(), ['name' => 'Bad']),
            $this->envelope('contact', (string) Str::ulid(), ['name' => 'Third']),
        ])->assertOk()->json('results');

        $this->assertSame('applied', $results[0]['status']);
        $this->assertSame('rejected', $results[1]['status']);
        $this->assertSame('applied', $results[2]['status']);

        $this->assertSame(2, Contact::count());
    }

    public function test_a_document_issued_offline_receives_a_server_assigned_number(): void
    {
        $contact = Contact::create(['name' => 'A Customer']);
        $documentId = (string) Str::ulid();
        $year = now()->format('Y');

        $response = $this->push([
            $this->envelope('document', $documentId, [
                'type' => DocumentType::Invoice->value,
                'contact_id' => $contact->id,
                'status' => DocumentStatus::Issued->value,
                'issue_date' => now()->toDateString(),
                'currency' => 'USD',
                'subtotal' => 500,
                'total' => 500,
                'balance' => 500,
            ], [
                'lines' => [[
                    'description' => 'Field work',
                    'quantity' => 1,
                    'unit_price' => 500,
                    'line_total' => 500,
                ]],
            ]),
        ])->assertOk();

        $response->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.0.assigned_number', "INV-{$year}-00001");

        $document = Document::with('lines')->find($documentId);

        $this->assertSame(DocumentStatus::Issued, $document->status);
        $this->assertNotNull($document->verification_token_id);
        $this->assertCount(1, $document->lines);
        // The hash must verify, or the printed copy would report as tampered.
        $this->assertFalse($document->isTampered());
    }

    public function test_a_device_cannot_claim_its_own_document_number(): void
    {
        $contact = Contact::create(['name' => 'A Customer']);
        $documentId = (string) Str::ulid();

        $this->push([
            $this->envelope('document', $documentId, [
                'type' => DocumentType::Invoice->value,
                'contact_id' => $contact->id,
                'status' => DocumentStatus::Draft->value,
                'number' => 'INV-FORGED-00001',
                'company_id' => 'some-other-company',
                'total' => 10,
                'balance' => 10,
            ]),
        ])->assertJsonPath('results.0.status', 'applied');

        $document = Document::find($documentId);

        // Server-owned columns are stripped from the payload.
        $this->assertNull($document->number);
        $this->assertSame($this->company->id, $document->company_id);
    }

    public function test_editing_an_issued_document_offline_is_reported_as_a_conflict(): void
    {
        $contact = Contact::create(['name' => 'A Customer']);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'number' => 'INV-X-1',
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Issued,
            'issue_date' => now()->toDateString(),
            'total' => 100,
            'balance' => 100,
        ]);

        $this->push([
            $this->envelope('document', $document->id, ['total' => 999], ['operation' => 'update']),
        ])->assertJsonPath('results.0.status', 'conflict');

        $this->assertSame('100.00', $document->fresh()->total);
    }

    public function test_pull_returns_changes_after_a_cursor(): void
    {
        $first = (string) Str::ulid();
        $second = (string) Str::ulid();

        $this->push([$this->envelope('contact', $first, ['name' => 'First'])]);

        $initial = $this->actingAs($this->user)->getJson('/api/sync/v1/pull')->assertOk();
        $cursor = $initial->json('cursors.contact');

        $this->assertCount(1, $initial->json('changes.contact'));

        $this->push([$this->envelope('contact', $second, ['name' => 'Second'])]);

        // Only what changed since the cursor comes back — the device does not
        // re-download its whole catalogue on every sync.
        $next = $this->actingAs($this->user)
            ->getJson('/api/sync/v1/pull?since[contact]='.$cursor)
            ->assertOk();

        $this->assertCount(1, $next->json('changes.contact'));
        $this->assertSame('Second', $next->json('changes.contact.0.name'));
    }

    public function test_pushing_registers_the_device_so_it_can_be_revoked(): void
    {
        $this->push([$this->envelope('contact', (string) Str::ulid(), ['name' => 'A'])])->assertOk();

        $device = Device::firstOrFail();

        $this->assertSame($this->user->id, $device->user_id);
        $this->assertNotNull($device->last_synced_at);
    }

    public function test_a_revoked_device_is_refused(): void
    {
        $device = Device::create([
            'user_id' => $this->user->id,
            'name' => 'Lost phone',
            'token_hash' => hash('sha256', 'x'),
            'revoked_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/sync/v1/push', [
                'device_id' => $device->id,
                'envelopes' => [$this->envelope('contact', (string) Str::ulid(), ['name' => 'A'])],
            ])
            ->assertForbidden();

        $this->assertSame(0, Contact::count());
    }

    public function test_sync_endpoints_require_authentication(): void
    {
        $this->postJson('/api/sync/v1/push', ['envelopes' => []])->assertUnauthorized();
        $this->getJson('/api/sync/v1/pull')->assertUnauthorized();
    }

    public function test_every_envelope_is_recorded_for_audit(): void
    {
        $this->push([
            $this->envelope('contact', (string) Str::ulid(), ['name' => 'Kept']),
            $this->envelope('nonsense', (string) Str::ulid(), []),
        ]);

        // Both outcomes are on the ledger, so a stuck device can be diagnosed
        // from the server rather than from the phone in someone's pocket.
        $this->assertSame(2, SyncReceipt::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, SyncReceipt::query()->withoutGlobalScopes()->where('status', 'rejected')->count());
    }
}
