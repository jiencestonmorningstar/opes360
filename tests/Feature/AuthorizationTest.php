<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Livewire\Documents\Create;
use App\Livewire\Documents\Show;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Permissions are only real if a lesser role is actually stopped. Every test
 * here drives a *non-owner* — the Owner short-circuits through Gate::before and
 * would pass regardless, which is exactly why it proves nothing on its own.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($this->company, $owner);
        app(CurrentCompany::class)->set($this->company);

        $this->contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);
    }

    /** A signed-in member of the company at the given role. */
    protected function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $this->joinCompany($this->company, $user, $role);
        $user->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($user);

        return $user;
    }

    protected function issuedInvoice(float $total = 400): Document
    {
        $document = Document::create([
            'type' => DocumentType::Invoice,
            'number' => 'INV-T-0001',
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Issued,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => 0,
            'balance' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Site works',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return $document->fresh();
    }

    public function test_every_seeded_permission_has_a_matching_gate(): void
    {
        // Drift here is silent in production: an ability with no gate is denied
        // to everyone but the Owner, and looks like it works in owner-run tests.
        foreach (Permissions::slugs() as $slug) {
            $this->assertTrue(
                Gate::has($slug),
                "Permission [{$slug}] is seeded but no gate defines it.",
            );
        }
    }

    public function test_a_cashier_cannot_open_reports_or_the_branding_studio(): void
    {
        $this->actingAsRole(Role::CASHIER);

        $this->get(route('reports'))->assertForbidden();
        $this->get(route('logo'))->assertForbidden();
        $this->get(route('products.create'))->assertForbidden();
    }

    public function test_a_cashier_can_still_reach_the_screens_their_job_needs(): void
    {
        $this->actingAsRole(Role::CASHIER);

        $this->get(route('sales'))->assertOk();
        $this->get(route('payments'))->assertOk();
        $this->get(route('customers'))->assertOk();
    }

    public function test_a_sales_officer_may_draft_but_may_not_issue(): void
    {
        $this->actingAsRole(Role::SALES_OFFICER);

        $component = Livewire::test(Create::class)
            ->set('contactId', $this->contact->id)
            ->set('lines', [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '250']]);

        // Drafting is within their remit.
        $component->call('saveDraft');
        $this->assertDatabaseCount('documents', 1);
        $this->assertSame(DocumentStatus::Draft, Document::first()->status);

        // Committing a permanent numbered document is not.
        Livewire::test(Create::class)
            ->set('contactId', $this->contact->id)
            ->set('lines', [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '250']])
            ->call('saveAndIssue')
            ->assertForbidden();

        $this->assertDatabaseCount('documents', 1);
    }

    public function test_an_accountant_may_issue(): void
    {
        $this->actingAsRole(Role::ACCOUNTANT);

        Livewire::test(Create::class)
            ->set('contactId', $this->contact->id)
            ->set('lines', [['description' => 'Consulting', 'quantity' => '1', 'unit_price' => '250']])
            ->call('saveAndIssue');

        $document = Document::first();

        $this->assertSame(DocumentStatus::Issued, $document->status);
        $this->assertNotNull($document->number);
    }

    public function test_only_a_role_with_the_void_right_can_void(): void
    {
        $invoice = $this->issuedInvoice();

        // The Accountant has sales.issue but deliberately not sales.void.
        $this->actingAsRole(Role::ACCOUNTANT);

        Livewire::test(Show::class, ['document' => $invoice])
            ->set('voidReason', 'Duplicate')
            ->call('voidDocument')
            ->assertForbidden();

        $this->assertSame(DocumentStatus::Issued, $invoice->fresh()->status);
    }

    public function test_a_read_only_user_cannot_record_a_payment(): void
    {
        $invoice = $this->issuedInvoice();

        $this->actingAsRole(Role::READ_ONLY);

        Livewire::test(Show::class, ['document' => $invoice])
            ->set('amount', '400')
            ->set('method', 'cash')
            ->call('recordPayment')
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0.0, (float) $invoice->fresh()->amount_paid);
    }

    public function test_navigation_hides_what_the_role_cannot_reach(): void
    {
        $this->actingAsRole(Role::CASHIER);

        $dashboard = $this->get(route('dashboard'))->assertOk();

        // Reports is gated and must not appear; Sales is permitted and must.
        $dashboard->assertDontSee(route('reports'));
        $dashboard->assertSee(route('sales'));

        // Quick actions follow the same rule — a Cashier cannot add a product.
        $dashboard->assertDontSee('Add Product');
        $dashboard->assertSee('Add Customer');

        // Nor is the create affordance offered on the index they can read.
        $this->get(route('products'))->assertOk()->assertDontSee(route('products.create'));
        $this->get(route('sales'))->assertOk()->assertDontSee(route('documents.create'));
    }

    public function test_document_actions_are_hidden_from_roles_that_cannot_use_them(): void
    {
        $invoice = $this->issuedInvoice();

        $this->actingAsRole(Role::READ_ONLY);

        $this->get(route('documents.show', $invoice))
            ->assertOk()
            // Reading the document is fine; acting on it is not.
            ->assertSee('Print / PDF')
            ->assertDontSee('Record Payment')
            ->assertDontSee('Void Invoice');
    }

    public function test_the_owner_is_never_locked_out(): void
    {
        $owner = $this->company->owner;
        $owner->forceFill(['current_company_id' => $this->company->id])->save();
        $this->actingAs($owner);

        $this->get(route('reports'))->assertOk();
        $this->get(route('logo'))->assertOk();
    }

    public function test_a_user_with_no_membership_is_denied_every_ability(): void
    {
        $stranger = User::factory()->create();

        foreach (['reports.view', 'sales.create', 'payments.record'] as $ability) {
            $this->assertFalse(
                Gate::forUser($stranger)->allows($ability),
                "A non-member was granted [{$ability}].",
            );
        }
    }

    public function test_authorization_fails_closed_when_no_company_is_current(): void
    {
        $user = $this->actingAsRole(Role::ACCOUNTANT);

        app(CurrentCompany::class)->set(null);

        $this->assertFalse(Gate::forUser($user)->allows('sales.issue'));

        $this->expectException(AuthorizationException::class);
        Gate::forUser($user)->authorize('sales.issue');
    }
}
