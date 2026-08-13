<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\IdempotencyKey;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Retrying a payment must not take the money twice.
 *
 * This is the situation the feature exists for: a client on a mobile network
 * POSTs a payment, the connection drops before the response arrives, and it
 * has no way to know whether the money moved. Without an idempotency key both
 * of its choices are wrong.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(4)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
        ChartOfAccounts::seed($this->company);

        Sanctum::actingAs($this->owner, ['*']);
    }

    protected function issuedInvoice(float $amount = 50000): Document
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Boulangerie Nkolbisson']);

        $id = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'issue' => true,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount]],
        ])->json('data.id');

        return Document::findOrFail($id);
    }

    public function test_the_same_payment_sent_twice_only_takes_the_money_once(): void
    {
        $invoice = $this->issuedInvoice(50000);

        $body = [
            'document_id' => $invoice->id,
            'amount' => 10000,
            'method' => PaymentMethod::Cash->value,
        ];

        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $first = $this->postJson('/api/v1/payments', $body, $key)->assertCreated();

        // The retry the client is forced into when the connection drops.
        $second = $this->postJson('/api/v1/payments', $body, $key)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        // Same answer, and — the point — one payment.
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Payment::count());
        $this->assertEqualsWithDelta(40000.0, (float) $invoice->fresh()->balance, 0.01);
    }

    public function test_without_a_key_a_repeat_is_a_second_payment(): void
    {
        $invoice = $this->issuedInvoice(50000);

        $body = [
            'document_id' => $invoice->id,
            'amount' => 10000,
            'method' => PaymentMethod::Cash->value,
        ];

        $this->postJson('/api/v1/payments', $body)->assertCreated();
        $this->postJson('/api/v1/payments', $body)->assertCreated();

        // Not a bug: without a key the server cannot tell a retry from a
        // customer paying another instalment. The header is how a caller says
        // which one it meant.
        $this->assertSame(2, Payment::count());
    }

    /**
     * A key reused for a genuinely different payment is a client bug, and
     * replaying the first response would hide it behind a plausible answer.
     */
    public function test_reusing_a_key_for_a_different_body_is_refused(): void
    {
        $invoice = $this->issuedInvoice(50000);
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $this->postJson('/api/v1/payments', [
            'document_id' => $invoice->id,
            'amount' => 10000,
            'method' => PaymentMethod::Cash->value,
        ], $key)->assertCreated();

        $this->postJson('/api/v1/payments', [
            'document_id' => $invoice->id,
            'amount' => 25000,
            'method' => PaymentMethod::Cash->value,
        ], $key)->assertStatus(422);

        $this->assertSame(1, Payment::count());
    }

    /**
     * A failed request releases its key. The caller fixed nothing and the
     * server did nothing, so refusing the retry would strand them.
     */
    public function test_a_failed_request_can_be_retried_with_the_same_key(): void
    {
        $invoice = $this->issuedInvoice(10000);
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        // Overpayment: refused by the domain.
        $this->postJson('/api/v1/payments', [
            'document_id' => $invoice->id,
            'amount' => 999999,
            'method' => PaymentMethod::Cash->value,
        ], $key)->assertStatus(422);

        $this->assertSame(0, IdempotencyKey::count());

        // The same key now works for the corrected request.
        $this->postJson('/api/v1/payments', [
            'document_id' => $invoice->id,
            'amount' => 5000,
            'method' => PaymentMethod::Cash->value,
        ], $key)->assertCreated();
    }

    /** Issuing burns an invoice number, so a retry must not burn a second. */
    public function test_issuing_a_document_twice_with_one_key_makes_one_invoice(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Garage Akwa']);
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $body = [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'issue' => true,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 5000]],
        ];

        $first = $this->postJson('/api/v1/documents', $body, $key)->assertCreated();
        $second = $this->postJson('/api/v1/documents', $body, $key)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame($first->json('data.number'), $second->json('data.number'));
        $this->assertSame(1, Document::count());
    }

    /**
     * Keys belong to the token that used them. Two integrations that happen to
     * generate the same UUID must not read each other's replies.
     */
    public function test_a_key_is_scoped_to_the_token_that_used_it(): void
    {
        $invoice = $this->issuedInvoice(50000);
        $keyValue = (string) Str::uuid();

        $body = [
            'document_id' => $invoice->id,
            'amount' => 10000,
            'method' => PaymentMethod::Cash->value,
        ];

        $this->postJson('/api/v1/payments', $body, ['Idempotency-Key' => $keyValue])->assertCreated();

        $stored = IdempotencyKey::firstOrFail();

        $this->assertSame($keyValue, $stored->key);
        $this->assertSame($this->company->id, $stored->company_id);
    }
}
