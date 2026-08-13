<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
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
 * Undoing a sale over the API.
 *
 * An issued document cannot be edited, which leaves a question the API has to
 * answer: how is a mistake corrected? Void it if nothing has been paid, credit
 * it if something has. Neither rewrites what the customer is holding.
 */
class DocumentLifecycleApiTest extends TestCase
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

    protected function contact(): Contact
    {
        return Contact::create(['type' => 'customer', 'name' => 'Boulangerie Nkolbisson']);
    }

    protected function issued(string $type = 'invoice', float $amount = 50000): string
    {
        return $this->postJson('/api/v1/documents', [
            'type' => $type,
            'contact_id' => $this->contact()->id,
            'issue' => true,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount]],
        ])->assertCreated()->json('data.id');
    }

    public function test_an_unpaid_invoice_can_be_voided(): void
    {
        $id = $this->issued();

        $this->postJson("/api/v1/documents/{$id}/void", ['reason' => 'Raised in error'])
            ->assertOk()
            ->assertJsonPath('data.status', 'void')
            ->assertJsonPath('data.balance', fn ($v) => (float) $v === 0.0);

        $this->assertSame(DocumentStatus::Void, Document::find($id)->status);
    }

    /**
     * Money already taken has to be dealt with first. Silently detaching it
     * would leave a receipt pointing at a document saying nothing was owed.
     */
    public function test_an_invoice_with_a_payment_against_it_cannot_be_voided(): void
    {
        $id = $this->issued(amount: 50000);

        $this->postJson('/api/v1/payments', [
            'document_id' => $id,
            'amount' => 10000,
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated();

        $this->postJson("/api/v1/documents/{$id}/void")->assertStatus(422);

        $this->assertNotSame(DocumentStatus::Void, Document::find($id)->status);
    }

    public function test_voiding_twice_is_refused(): void
    {
        $id = $this->issued();

        $this->postJson("/api/v1/documents/{$id}/void")->assertOk();
        $this->postJson("/api/v1/documents/{$id}/void")->assertStatus(422);
    }

    /** A paid invoice is corrected by crediting it, not by rewriting it. */
    public function test_a_paid_invoice_can_be_credited(): void
    {
        $id = $this->issued(amount: 50000);
        $total = (float) Document::findOrFail($id)->total;

        $this->postJson('/api/v1/payments', [
            'document_id' => $id,
            'amount' => $total,
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated();

        $this->postJson("/api/v1/documents/{$id}/credit-note", [
            'amount' => $total,
            'reason' => 'Goods returned',
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'credit_note');
    }

    public function test_a_quotation_converts_to_an_invoice(): void
    {
        $id = $this->issued('quotation', 30000);

        $invoice = $this->postJson("/api/v1/documents/{$id}/convert")
            ->assertCreated()
            ->assertJsonPath('data.type', 'invoice')
            ->json('data.id');

        $this->assertNotSame($id, $invoice);
        // The converted invoice is a real, numbered document of its own.
        $this->assertNotNull(Document::find($invoice)->number);
    }

    /**
     * Voiding is its own permission: a Sales Officer may raise an invoice
     * without being able to make one disappear.
     */
    public function test_a_sales_officer_cannot_void(): void
    {
        $id = $this->issued();

        $officer = User::factory()->create();
        $this->joinCompany($this->company, $officer, 'sales-officer');
        $officer->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($officer, ['*']);

        $this->postJson("/api/v1/documents/{$id}/void")->assertForbidden();
    }

    /** Retrying a dropped void must not produce a second reversal. */
    public function test_voiding_is_idempotent_under_a_key(): void
    {
        $id = $this->issued();
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $this->postJson("/api/v1/documents/{$id}/void", [], $key)->assertOk();

        $this->postJson("/api/v1/documents/{$id}/void", [], $key)
            ->assertOk()
            ->assertHeader('Idempotent-Replay', 'true');
    }
}
