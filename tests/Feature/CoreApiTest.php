<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Item;
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
 * Customers, products and sales documents over the token API.
 *
 * These endpoints were retrofitted onto modules that already had screens, so
 * the assertions worth making are about the rules the screens enforce holding
 * here too: an issued document stays immutable, TVA matches the one figure the
 * rest of the app computes, and stock is not settable by hand.
 */
class CoreApiTest extends TestCase
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
            'vat_registered' => true,
            'vat_rate' => 19.25,
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
        ChartOfAccounts::seed($this->company);

        Sanctum::actingAs($this->owner, ['*']);
    }

    protected function makeContact(string $name = 'Boulangerie Nkolbisson'): Contact
    {
        return Contact::create(['type' => 'customer', 'name' => $name]);
    }

    // ── Contacts ─────────────────────────────────────────────────────────

    public function test_a_contact_can_be_created_and_listed(): void
    {
        $this->postJson('/api/v1/contacts', [
            'name' => 'Boulangerie Nkolbisson',
            'phone' => '+237670000000',
            'city' => 'Yaoundé',
        ])->assertCreated()->assertJsonPath('data.name', 'Boulangerie Nkolbisson');

        $this->getJson('/api/v1/contacts')
            ->assertOk()
            ->assertJsonPath('data.0.phones.0', '+237670000000')
            ->assertJsonPath('data.0.address.city', 'Yaoundé');
    }

    /** A partial update must not blank the fields it did not mention. */
    public function test_updating_one_field_leaves_the_others_alone(): void
    {
        $id = $this->postJson('/api/v1/contacts', [
            'name' => 'Garage Akwa',
            'phone' => '+237699000000',
            'city' => 'Douala',
        ])->json('data.id');

        $this->patchJson("/api/v1/contacts/{$id}", ['name' => 'Garage Akwa Ltd'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Garage Akwa Ltd')
            ->assertJsonPath('data.phones.0', '+237699000000')
            ->assertJsonPath('data.address.city', 'Douala');
    }

    public function test_contacts_can_be_searched_and_filtered_by_type(): void
    {
        $this->postJson('/api/v1/contacts', ['name' => 'Boulangerie Nkolbisson']);
        $this->postJson('/api/v1/contacts', ['name' => 'Prime Supplies', 'type' => 'supplier']);

        $this->getJson('/api/v1/contacts?type=supplier')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Prime Supplies');

        $this->getJson('/api/v1/contacts?q=Boulangerie')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Items ────────────────────────────────────────────────────────────

    public function test_an_item_can_be_created_and_read_back(): void
    {
        $id = $this->postJson('/api/v1/items', [
            'name' => 'Ciment 50kg',
            'sku' => 'CIM-50',
            'price' => 6500,
            'cost' => 5800,
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/v1/items/{$id}")
            ->assertOk()
            ->assertJsonPath('data.sku', 'CIM-50')
            ->assertJsonPath('data.price', fn ($v) => (float) $v === 6500.0)
            ->assertJsonPath('data.is_active', true);
    }

    /**
     * Quantity on hand is the result of recorded movements. An endpoint that
     * let a caller set it would put the books and the shelf permanently out of
     * agreement with nothing explaining why.
     */
    public function test_stock_cannot_be_set_through_the_item_endpoint(): void
    {
        $id = $this->postJson('/api/v1/items', [
            'name' => 'Ciment 50kg',
            'price' => 6500,
            'stock' => 999,
            'quantity' => 999,
        ])->assertCreated()->json('data.id');

        $item = Item::findOrFail($id);

        $this->assertArrayNotHasKey('stock', $item->getAttributes());
        $this->assertArrayNotHasKey('quantity', $item->getAttributes());
    }

    // ── Documents ────────────────────────────────────────────────────────

    public function test_an_invoice_is_created_as_a_draft_with_tva_computed(): void
    {
        $contact = $this->makeContact();

        $response = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'lines' => [
                ['description' => 'Ciment 50kg', 'quantity' => 10, 'unit_price' => 6500],
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', fn ($v) => (float) $v === 65000.0)
            /*
             * 19.25% of 65,000 is 12,512.50, and the figure here is 12,513:
             * the franc has no minor unit, so the tax engine rounds to whole
             * francs. That rounding is the product's, not this test's, and
             * pinning it here is what stops it drifting.
             */
            ->assertJsonPath('data.tax_total', fn ($v) => (float) $v === 12513.0)
            ->assertJsonPath('data.total', fn ($v) => (float) $v === 77513.0);

        // A draft has no number yet: numbers are assigned at issue.
        $this->assertNull($response->json('data.number'));
    }

    public function test_a_document_can_be_issued_and_gets_its_number(): void
    {
        $contact = $this->makeContact();

        $id = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 50000]],
        ])->json('data.id');

        $this->postJson("/api/v1/documents/{$id}/issue")
            ->assertOk()
            ->assertJsonPath('data.status', 'issued');

        $this->assertNotNull(Document::find($id)->number);
    }

    public function test_a_document_can_be_created_and_issued_in_one_call(): void
    {
        $contact = $this->makeContact();

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'issue' => true,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 25000]],
        ])->assertCreated()->assertJsonPath('data.status', 'issued');
    }

    public function test_issuing_the_same_document_twice_is_refused(): void
    {
        $contact = $this->makeContact();

        $id = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'issue' => true,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000]],
        ])->json('data.id');

        $this->postJson("/api/v1/documents/{$id}/issue")->assertStatus(422);
    }

    /**
     * The immutability rule, from the caller's side: a customer holding a
     * printed invoice must be able to trust the copy on file still agrees.
     */
    public function test_an_issued_document_cannot_be_deleted(): void
    {
        $contact = $this->makeContact();

        $id = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'issue' => true,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000]],
        ])->json('data.id');

        $this->deleteJson("/api/v1/documents/{$id}")->assertStatus(422);

        $this->assertNotNull(Document::find($id));
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $contact = $this->makeContact();

        $id = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 1000]],
        ])->json('data.id');

        $this->deleteJson("/api/v1/documents/{$id}")->assertNoContent();
    }

    public function test_a_document_needs_at_least_one_line(): void
    {
        $contact = $this->makeContact();

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'lines' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('lines');
    }

    public function test_documents_can_be_filtered_to_the_outstanding_ones(): void
    {
        $contact = $this->makeContact();

        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'issue' => true,
            'lines' => [['description' => 'Owed', 'quantity' => 1, 'unit_price' => 1000]],
        ]);

        // A draft is not outstanding: nothing is owed until it is issued.
        $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'lines' => [['description' => 'Draft', 'quantity' => 1, 'unit_price' => 5000]],
        ]);

        $this->getJson('/api/v1/documents?outstanding=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // Lines are absent from the list on purpose — see DocumentResource,
            // which only emits them when they have been eager-loaded.
            ->assertJsonPath('data.0.total', fn ($v) => (float) $v > 0.0)
            ->assertJsonPath('data.0.status', 'issued');
    }

    // ── Boundaries ───────────────────────────────────────────────────────

    public function test_a_cashier_cannot_create_a_product(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($cashier, ['*']);

        $this->postJson('/api/v1/items', ['name' => 'Nope', 'price' => 1])->assertForbidden();
    }

    public function test_another_companys_records_are_not_reachable(): void
    {
        $stranger = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(4)),
            'name' => 'Other Sarl',
            'owner_id' => $stranger->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);
        $this->joinCompany($other, $stranger, Role::OWNER);

        app(CurrentCompany::class)->set($other);
        $theirContact = Contact::create(['type' => 'customer', 'name' => 'Not yours']);
        app(CurrentCompany::class)->set($this->company);

        Sanctum::actingAs($this->owner, ['*']);

        $this->getJson("/api/v1/contacts/{$theirContact->id}")->assertNotFound();
    }
}
