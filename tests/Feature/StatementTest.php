<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Payment;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The statement of account is a report over the ledger, so the thing to prove
 * is the arithmetic: charges up, payments down, closing balance honest.
 */
class StatementTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
        ]);

        $this->joinCompany($company, $this->user);
        $this->user->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $this->contact = Contact::create(['name' => 'Tech Core Ltd', 'balance' => 0]);
    }

    public function test_the_statement_lists_charges_and_payments_with_a_running_balance(): void
    {
        Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Issued,
            'number' => 'INV-2026-00001',
            'issue_date' => now()->subDays(20)->toDateString(),
            'currency' => 'USD',
            'subtotal' => 900,
            'total' => 900,
            'balance' => 900,
        ]);

        Payment::create([
            'contact_id' => $this->contact->id,
            'reference' => 'PAY-0001',
            'amount' => 400,
            'method' => 'cash',
            'received_at' => now()->subDays(10),
        ]);

        $response = $this->actingAs($this->user)->get('/customers/'.$this->contact->id.'/statement');

        $response->assertOk()
            ->assertSee('Statement of Account')
            ->assertSee('Tech Core Ltd')
            ->assertSee('INV-2026-00001')
            ->assertSee('Payment received')
            // 900 charged, 400 paid: the closing balance is the difference.
            ->assertSee('$500.00');
    }

    public function test_quotations_and_drafts_stay_off_the_statement(): void
    {
        Document::create([
            'type' => DocumentType::Quotation,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Issued,
            'number' => 'QUO-2026-00001',
            'issue_date' => now()->subDays(5)->toDateString(),
            'currency' => 'USD',
            'subtotal' => 100,
            'total' => 100,
            'balance' => 100,
        ]);

        Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->subDays(5)->toDateString(),
            'currency' => 'USD',
            'subtotal' => 200,
            'total' => 200,
            'balance' => 200,
        ]);

        $this->actingAs($this->user)
            ->get('/customers/'.$this->contact->id.'/statement')
            ->assertOk()
            ->assertDontSee('QUO-2026-00001')
            ->assertSee('No invoices or payments in this period');
    }

    public function test_a_statement_for_another_companys_customer_is_unreachable(): void
    {
        $rivalOwner = User::factory()->create();
        $rival = Company::create([
            'slug' => 'rival',
            'name' => 'Rival Ltd',
            'owner_id' => $rivalOwner->id,
            'currency' => 'USD',
        ]);
        $this->joinCompany($rival, $rivalOwner);
        $rivalOwner->forceFill(['current_company_id' => $rival->id])->save();

        $this->actingAs($rivalOwner)
            ->get('/customers/'.$this->contact->id.'/statement')
            ->assertNotFound();
    }
}
