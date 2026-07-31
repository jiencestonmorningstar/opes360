<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Customers\Show;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Role;
use App\Models\User;
use App\Services\LoyaltyLedger;
use App\Services\PaymentRecorder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Loyalty points are a ledger, not a counter — every earn/redeem/adjust is a
 * row, and contacts.loyalty_points is only ever a derived cache of it. The
 * card is a physical object with a QR that resolves through the same public
 * verification page as every other printed artifact in the product.
 */
class LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Company $company;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->owner->id,
            'currency' => 'USD',
            'loyalty_enabled' => true,
            'loyalty_points_per_amount' => 100,
            'loyalty_point_value' => 1,
        ]);

        $this->joinCompany($this->company, $this->owner);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        $this->contact = Contact::create(['type' => 'customer', 'name' => 'A Customer', 'balance' => 0]);
    }

    protected function paidInvoice(float $total): Document
    {
        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Issued,
            'number' => 'INV-2026-'.random_int(10000, 99999),
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => $total,
            'total' => $total,
            'balance' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Work',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return $document;
    }

    public function test_recording_a_payment_earns_points_at_the_company_rate(): void
    {
        app(PaymentRecorder::class)->record($this->paidInvoice(250), $this->owner, 250.0, PaymentMethod::Cash);

        // 250 / 100 per point = 2 points, floored.
        $this->assertSame(2, $this->contact->fresh()->loyalty_points);
        $this->assertDatabaseHas('loyalty_transactions', [
            'contact_id' => $this->contact->id,
            'type' => 'earn',
            'points' => 2,
            'balance_after' => 2,
        ]);
    }

    public function test_no_points_when_the_program_is_off(): void
    {
        $this->company->update(['loyalty_enabled' => false]);

        app(PaymentRecorder::class)->record($this->paidInvoice(500), $this->owner, 500.0, PaymentMethod::Cash);

        $this->assertSame(0, $this->contact->fresh()->loyalty_points);
    }

    public function test_a_spend_below_the_point_threshold_earns_nothing(): void
    {
        app(PaymentRecorder::class)->record($this->paidInvoice(50), $this->owner, 50.0, PaymentMethod::Cash);

        $this->assertSame(0, $this->contact->fresh()->loyalty_points);
        $this->assertDatabaseMissing('loyalty_transactions', ['contact_id' => $this->contact->id]);
    }

    public function test_issuing_a_card_mints_a_number_and_a_verification_token(): void
    {
        $issued = app(LoyaltyLedger::class)->issueCard($this->contact);

        $this->assertNotNull($issued->loyalty_card_number);
        $this->assertStringStartsWith('LOY-', $issued->loyalty_card_number);
        $this->assertNotNull($issued->loyaltyVerificationToken);

        // Issuing twice must not mint a second card or a second token.
        $again = app(LoyaltyLedger::class)->issueCard($issued);
        $this->assertSame($issued->loyalty_card_number, $again->loyalty_card_number);
    }

    public function test_the_printed_card_renders_with_a_qr(): void
    {
        $this->actingAs($this->owner)
            ->get('/customers/'.$this->contact->id.'/loyalty-card/print')
            ->assertOk()
            ->assertSee('<svg', false);

        $this->assertTrue($this->contact->fresh()->hasLoyaltyCard());
    }

    public function test_the_card_verifies_publicly_and_shows_the_balance(): void
    {
        app(LoyaltyLedger::class)->earn($this->contact, $this->company, 300, Document::class, 'x', $this->owner);
        $card = app(LoyaltyLedger::class)->issueCard($this->contact->fresh());

        $this->get('/v/'.$card->loyaltyVerificationToken->token)
            ->assertOk()
            ->assertSee('Verified authentic')
            ->assertSee('3'); // points balance
    }

    public function test_staff_can_redeem_from_the_verification_page(): void
    {
        app(LoyaltyLedger::class)->earn($this->contact, $this->company, 500, Document::class, 'x', $this->owner);
        $card = app(LoyaltyLedger::class)->issueCard($this->contact->fresh());

        $this->actingAs($this->owner)
            ->from('/v/'.$card->loyaltyVerificationToken->token)
            ->post('/customers/'.$card->id.'/loyalty/redeem', ['points' => 3])
            ->assertRedirect('/v/'.$card->loyaltyVerificationToken->token);

        $this->assertSame(2, $this->contact->fresh()->loyalty_points);
        $this->assertDatabaseHas('loyalty_transactions', [
            'contact_id' => $this->contact->id,
            'type' => 'redeem',
            'points' => -3,
        ]);
    }

    public function test_redeeming_more_than_the_balance_is_refused(): void
    {
        app(LoyaltyLedger::class)->earn($this->contact, $this->company, 100, Document::class, 'x', $this->owner);

        $this->actingAs($this->owner)
            ->post('/customers/'.$this->contact->id.'/loyalty/redeem', ['points' => 5])
            ->assertSessionHasErrors('points');

        $this->assertSame(1, $this->contact->fresh()->loyalty_points);
    }

    public function test_a_cashier_can_redeem_but_not_manage_the_program(): void
    {
        $cashier = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->joinCompany($this->company, $cashier, Role::CASHIER);

        app(LoyaltyLedger::class)->earn($this->contact, $this->company, 100, Document::class, 'x', $this->owner);

        $this->actingAs($cashier)
            ->post('/customers/'.$this->contact->id.'/loyalty/redeem', ['points' => 1])
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($cashier)
            ->post('/customers/'.$this->contact->id.'/loyalty-card/issue')
            ->assertForbidden();
    }

    public function test_the_customer_show_screen_issues_and_redeems(): void
    {
        app(LoyaltyLedger::class)->earn($this->contact, $this->company, 400, Document::class, 'x', $this->owner);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['contact' => $this->contact])
            ->call('issueLoyaltyCard')
            ->assertSet('redeemPoints', '')
            ->set('redeemPoints', 2)
            ->call('redeemLoyaltyPoints');

        $fresh = $this->contact->fresh();
        $this->assertTrue($fresh->hasLoyaltyCard());
        $this->assertSame(2, $fresh->loyalty_points);
    }

    public function test_loyalty_actions_are_invisible_across_companies(): void
    {
        app(LoyaltyLedger::class)->earn($this->contact, $this->company, 100, Document::class, 'x', $this->owner);

        $rivalOwner = User::factory()->create();
        $rival = Company::create([
            'slug' => 'rival', 'name' => 'Rival Ltd', 'owner_id' => $rivalOwner->id, 'currency' => 'USD',
        ]);
        $this->joinCompany($rival, $rivalOwner);
        $rivalOwner->forceFill(['current_company_id' => $rival->id])->save();

        $this->actingAs($rivalOwner)
            ->post('/customers/'.$this->contact->id.'/loyalty/redeem', ['points' => 1])
            ->assertNotFound();
    }
}
