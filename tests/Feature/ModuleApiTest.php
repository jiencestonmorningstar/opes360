<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Expense;
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
 * Payments, expenses, the books and the staff file over the token API.
 *
 * These modules all had screens and services already, so what is worth
 * asserting is that the API reaches the same services rather than writing
 * rows beside them — a payment that produces a receipt and moves the invoice's
 * balance, an expense that reaches the ledger, and books that stay read-only.
 */
class ModuleApiTest extends TestCase
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

        Sanctum::actingAs($this->owner);
    }

    /** An issued invoice, which is the only thing a payment can be taken against. */
    protected function issuedInvoice(float $amount = 50000): Document
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Boulangerie Nkolbisson']);

        $id = $this->postJson('/api/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'issue' => true,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount]],
        ])->json('data.id');

        return Document::findOrFail($id);
    }

    // ── Payments ─────────────────────────────────────────────────────────

    public function test_a_payment_produces_a_receipt_and_moves_the_balance(): void
    {
        $invoice = $this->issuedInvoice(50000);
        $owed = (float) $invoice->balance;

        $response = $this->postJson('/api/payments', [
            'document_id' => $invoice->id,
            'amount' => $owed,
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated();

        // The receipt is the point: numbered, and carrying the token its QR
        // is built from, or a printed copy could never be verified.
        $this->assertNotNull($response->json('data.receipt.number'));
        $this->assertNotNull($response->json('data.receipt.verification_token'));

        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->balance, 0.01);
    }

    public function test_a_partial_payment_leaves_the_rest_owing(): void
    {
        $invoice = $this->issuedInvoice(50000);
        $owed = (float) $invoice->balance;

        $this->postJson('/api/payments', [
            'document_id' => $invoice->id,
            'amount' => round($owed / 2, 2),
            'method' => PaymentMethod::MobileMoney->value,
        ])->assertCreated();

        $this->assertEqualsWithDelta($owed / 2, (float) $invoice->fresh()->balance, 0.05);
    }

    /**
     * Overpayment is refused rather than absorbed — customer credit is a real
     * feature, not a negative balance nobody meant to create.
     */
    public function test_paying_more_than_is_owed_is_refused(): void
    {
        $invoice = $this->issuedInvoice(10000);

        $this->postJson('/api/payments', [
            'document_id' => $invoice->id,
            'amount' => (float) $invoice->balance + 1000,
            'method' => PaymentMethod::Cash->value,
        ])->assertStatus(422);
    }

    public function test_a_draft_cannot_be_paid(): void
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Garage Akwa']);

        $draft = $this->postJson('/api/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 5000]],
        ])->json('data.id');

        $this->postJson('/api/payments', [
            'document_id' => $draft,
            'amount' => 1000,
            'method' => PaymentMethod::Cash->value,
        ])->assertStatus(422);
    }

    // ── Expenses ─────────────────────────────────────────────────────────

    public function test_an_expense_is_recorded_and_reaches_the_books(): void
    {
        $before = $this->getJson('/api/accounting/journal')->json('data');

        $this->postJson('/api/expenses', [
            'description' => 'Carburant',
            'category' => array_key_first(ChartOfAccounts::EXPENSE_CATEGORIES),
            'issue_date' => now()->toDateString(),
            'amount' => 25000,
            'payment_method' => 'cash',
        ])->assertCreated()->assertJsonPath('data.description', 'Carburant');

        $after = $this->getJson('/api/accounting/journal')->json('data');

        // Recording an expense is a business event, so the books moved.
        $this->assertGreaterThan(count($before), count($after));
    }

    public function test_an_expense_can_be_settled_and_then_shows_no_balance(): void
    {
        $id = $this->postJson('/api/expenses', [
            'description' => 'Facture fournisseur',
            'category' => array_key_first(ChartOfAccounts::EXPENSE_CATEGORIES),
            'issue_date' => now()->toDateString(),
            'amount' => 40000,
        ])->json('data.id');

        $total = (float) Expense::findOrFail($id)->total;

        $this->postJson("/api/expenses/{$id}/settle", [
            'amount' => $total,
            'method' => 'cash',
        ])->assertOk()->assertJsonPath('data.balance', fn ($v) => (float) $v === 0.0);
    }

    public function test_settling_more_than_is_owing_is_refused(): void
    {
        $id = $this->postJson('/api/expenses', [
            'description' => 'Facture',
            'category' => array_key_first(ChartOfAccounts::EXPENSE_CATEGORIES),
            'issue_date' => now()->toDateString(),
            'amount' => 10000,
        ])->json('data.id');

        $this->postJson("/api/expenses/{$id}/settle", [
            'amount' => 999999,
            'method' => 'cash',
        ])->assertStatus(422);
    }

    /** A fraction, not a percentage: 19.25 would be a 1,925% expense. */
    public function test_a_vat_rate_above_one_is_refused(): void
    {
        $this->postJson('/api/expenses', [
            'description' => 'Carburant',
            'category' => array_key_first(ChartOfAccounts::EXPENSE_CATEGORIES),
            'issue_date' => now()->toDateString(),
            'amount' => 25000,
            'vat_rate' => 19.25,
        ])->assertStatus(422)->assertJsonValidationErrors('vat_rate');
    }

    // ── Accounting ───────────────────────────────────────────────────────

    public function test_the_books_can_be_read(): void
    {
        $this->getJson('/api/accounting/accounts')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/accounting/trial-balance')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/accounting/income-statement')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/accounting/balance-sheet')->assertOk()->assertJsonStructure(['data']);
    }

    /**
     * There is no route to post a journal entry by hand, and that is the
     * design: every entry is the consequence of a business event that has its
     * own endpoint.
     */
    public function test_the_books_cannot_be_written_to(): void
    {
        $this->postJson('/api/accounting/journal', [])->assertStatus(405);
    }

    public function test_a_cashier_cannot_read_the_books(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($cashier);

        $this->getJson('/api/accounting/trial-balance')->assertForbidden();
    }

    // ── Employees and payroll ────────────────────────────────────────────

    public function test_an_employee_can_be_added_and_listed(): void
    {
        $this->postJson('/api/employees', [
            'first_name' => 'Marie',
            'last_name' => 'Ngo',
            'job_title' => 'Comptable',
            'hired_on' => '2026-01-15',
        ])->assertCreated()->assertJsonPath('data.first_name', 'Marie');

        $this->getJson('/api/employees')->assertOk()->assertJsonPath('data.0.last_name', 'Ngo');
    }

    /**
     * The identity-theft-shaped fields stay out of the payload. Every manager
     * holds `employees.view`, and nothing has needed to read these back.
     */
    public function test_identity_numbers_are_not_returned(): void
    {
        $employee = Employee::create([
            'first_name' => 'Marie',
            'last_name' => 'Ngo',
            'status' => 'active',
            'national_id' => '1234567890',
            'cnps_number' => 'CNPS-999',
            'bank_account' => '00012345678',
        ]);

        $body = $this->getJson("/api/employees/{$employee->id}")->assertOk()->json('data');

        foreach (['national_id', 'cnps_number', 'bank_account', 'niu', 'emergency_phone'] as $field) {
            $this->assertArrayNotHasKey($field, $body);
        }
    }

    public function test_a_cashier_cannot_read_the_staff_file(): void
    {
        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($cashier);

        $this->getJson('/api/employees')->assertForbidden();
    }

    public function test_payroll_runs_are_readable_but_not_runnable(): void
    {
        $this->getJson('/api/payroll/runs')->assertOk()->assertJsonStructure(['data']);

        // Approving a month commits the business to its wages and the
        // declarations that follow. There is no route for it on purpose.
        $this->postJson('/api/payroll/runs', [])->assertStatus(405);
    }
}
