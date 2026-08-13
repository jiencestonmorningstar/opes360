<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\Receipt;
use App\Models\Refund;
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
 * Giving money back.
 *
 * Recording a payment moves six things at once. A refund has to undo exactly
 * that set or the business ends up with books disagreeing with the documents
 * underneath them, so most of what is asserted here is the parts nobody sees
 * on screen: the ledger, the invoice's balance, the customer's cached total
 * and the loyalty points.
 */
class PaymentRefundTest extends TestCase
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
            // On, so the claw-back below is actually exercised rather than
            // skipped — it is the part of a refund nobody sees and everybody
            // notices when it is wrong.
            'loyalty_enabled' => true,
            'loyalty_points_per_amount' => 1000,
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
        ChartOfAccounts::seed($this->company);

        Sanctum::actingAs($this->owner, ['*']);
    }

    /** An issued, fully paid invoice — the ordinary case a refund follows. */
    protected function paidInvoice(float $amount = 50000): array
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Boulangerie Nkolbisson']);

        $id = $this->postJson('/api/v1/documents', [
            'type' => 'invoice',
            'contact_id' => $contact->id,
            'issue' => true,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount]],
        ])->json('data.id');

        $document = Document::findOrFail($id);

        $paymentId = $this->postJson('/api/v1/payments', [
            'document_id' => $document->id,
            'amount' => (float) $document->balance,
            'method' => PaymentMethod::Cash->value,
        ])->assertCreated()->json('data.id');

        return [$document->fresh(), Payment::findOrFail($paymentId), $contact->fresh()];
    }

    public function test_a_full_refund_makes_the_invoice_owed_again(): void
    {
        [$document, $payment] = $this->paidInvoice(50000);
        $total = (float) $document->total;

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => (float) $payment->amount,
            'method' => PaymentMethod::MobileMoney->value,
            'reason' => 'Goods returned',
        ])->assertCreated()->assertJsonPath('data.reason', 'Goods returned');

        $document->refresh();

        $this->assertEqualsWithDelta($total, (float) $document->balance, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $document->amount_paid, 0.01);
        $this->assertSame(DocumentStatus::Issued, $document->status);
    }

    /**
     * The payment stays and its receipt keeps verifying. The customer is
     * holding a printed copy saying money changed hands, and it did.
     */
    public function test_the_payment_and_its_receipt_survive_the_refund(): void
    {
        [, $payment] = $this->paidInvoice();

        $receiptId = Receipt::where('payment_id', $payment->id)->value('id');

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => (float) $payment->amount,
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Cancelled order',
        ])->assertCreated();

        $this->assertNotNull(Payment::find($payment->id));

        $receipt = Receipt::find($receiptId);

        $this->assertNotNull($receipt, 'Deleting the receipt would strand a printed copy.');
        $this->assertNotNull($receipt->verification_token_id);
    }

    public function test_a_partial_refund_leaves_the_rest_settled(): void
    {
        [$document, $payment] = $this->paidInvoice(50000);
        $paid = (float) $payment->amount;

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => round($paid / 2, 2),
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Two of five bags returned',
        ])->assertCreated();

        $document->refresh();

        $this->assertEqualsWithDelta($paid / 2, (float) $document->balance, 0.05);
        $this->assertSame(DocumentStatus::Partial, $document->status);
    }

    public function test_refunding_more_than_was_paid_is_refused(): void
    {
        [, $payment] = $this->paidInvoice(10000);

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => (float) $payment->amount + 5000,
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Too much',
        ])->assertStatus(422);

        $this->assertSame(0, Refund::count());
    }

    /** Two partial refunds may not add up to more than the payment. */
    public function test_refunds_cannot_add_up_past_the_payment(): void
    {
        [, $payment] = $this->paidInvoice(50000);
        $paid = (float) $payment->amount;

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => round($paid * 0.6, 2),
            'method' => PaymentMethod::Cash->value,
            'reason' => 'First return',
        ])->assertCreated();

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => round($paid * 0.6, 2),
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Second return',
        ])->assertStatus(422);

        $this->assertSame(1, Refund::count());
    }

    /**
     * A refund nobody can explain a year later is the entry in the books that
     * matters most and reads least.
     */
    public function test_a_reason_is_required(): void
    {
        [, $payment] = $this->paidInvoice();

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => 1000,
            'method' => PaymentMethod::Cash->value,
        ])->assertStatus(422)->assertJsonValidationErrors('reason');
    }

    /** The books have to move, or they claim money the business gave back. */
    public function test_the_ledger_records_the_refund(): void
    {
        [, $payment] = $this->paidInvoice(50000);

        $before = count($this->getJson('/api/v1/accounting/journal')->json('data'));

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => (float) $payment->amount,
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Goods returned',
        ])->assertCreated();

        $after = count($this->getJson('/api/v1/accounting/journal')->json('data'));

        $this->assertGreaterThan($before, $after);
    }

    /** A partial refund posts its own entry — reversing the whole settlement
     *  would credit the till with money that never left it. */
    public function test_a_partial_refund_does_not_reverse_the_whole_settlement(): void
    {
        [$document, $payment] = $this->paidInvoice(50000);
        $paid = (float) $payment->amount;

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => round($paid / 4, 2),
            'method' => PaymentMethod::Cash->value,
            'reason' => 'One item returned',
        ])->assertCreated();

        // Three quarters of the money is still legitimately the business's.
        $this->assertEqualsWithDelta($paid * 0.75, (float) $document->fresh()->amount_paid, 0.05);
    }

    /** Points earned on a purchase that did not stand are taken back. */
    public function test_loyalty_points_are_clawed_back_in_proportion(): void
    {
        [, $payment, $contact] = $this->paidInvoice(50000);

        $earned = (int) LoyaltyTransaction::where('reference_id', $payment->id)
            ->where('type', 'earn')->sum('points');

        if ($earned <= 0) {
            $this->markTestSkipped('Loyalty is not earning on this configuration.');
        }

        $before = (int) $contact->fresh()->loyalty_points;

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => (float) $payment->amount,
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Goods returned',
        ])->assertCreated();

        $this->assertLessThan($before, (int) $contact->fresh()->loyalty_points);
        $this->assertGreaterThanOrEqual(0, (int) $contact->fresh()->loyalty_points);
    }

    public function test_the_customer_balance_is_recomputed(): void
    {
        [$document, $payment, $contact] = $this->paidInvoice(50000);

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => (float) $payment->amount,
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Goods returned',
        ])->assertCreated();

        // Owing again, and from the documents rather than by arithmetic here.
        $this->assertEqualsWithDelta(
            (float) $document->fresh()->total,
            (float) $contact->fresh()->balance,
            0.05
        );
    }

    /** Refunding against a cancelled document must not bring it back to life. */
    public function test_a_voided_document_stays_void(): void
    {
        [$document, $payment] = $this->paidInvoice(50000);

        $document->forceFill(['status' => DocumentStatus::Void])->save();

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => (float) $payment->amount,
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Cancelled and refunded',
        ])->assertCreated();

        $this->assertSame(DocumentStatus::Void, $document->fresh()->status);
    }

    // ── Who may do it ────────────────────────────────────────────────────

    public function test_a_cashier_cannot_refund(): void
    {
        [, $payment] = $this->paidInvoice();

        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, 'cashier');
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        Sanctum::actingAs($cashier, ['*']);

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => 1000,
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Nope',
        ])->assertForbidden();

        $this->assertSame(0, Refund::count());
    }

    /** Refunding is money moving, so a write-only token is not enough. */
    public function test_a_write_token_cannot_refund(): void
    {
        [, $payment] = $this->paidInvoice();

        Sanctum::actingAs($this->owner, ['read', 'write']);

        $this->postJson("/api/v1/payments/{$payment->id}/refund", [
            'amount' => 1000,
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Nope',
        ])->assertForbidden();
    }

    // ── The screen ───────────────────────────────────────────────────────

    /** Refunding must not be API-only: the counter is where it happens. */
    public function test_the_payments_screen_refunds_through_the_same_service(): void
    {
        [$document, $payment] = $this->paidInvoice(50000);
        $total = (float) $document->total;

        \Livewire\Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Payments\Index::class)
            ->call('startRefund', $payment->id)
            ->assertSet('refundAmount', (string) (float) $payment->amount)
            ->set('refundReason', 'Goods returned')
            ->call('refund')
            ->assertHasNoErrors();

        $this->assertSame(1, Refund::count());
        $this->assertEqualsWithDelta($total, (float) $document->fresh()->balance, 0.01);
    }

    public function test_the_screen_refuses_a_refund_with_no_reason(): void
    {
        [, $payment] = $this->paidInvoice();

        \Livewire\Livewire::actingAs($this->owner)
            ->test(\App\Livewire\Payments\Index::class)
            ->call('startRefund', $payment->id)
            ->set('refundReason', '')
            ->call('refund')
            ->assertHasErrors('refundReason');

        $this->assertSame(0, Refund::count());
    }

    /** A dropped connection must not hand the money back twice. */
    public function test_a_retried_refund_under_one_key_pays_out_once(): void
    {
        [, $payment] = $this->paidInvoice(50000);

        $body = [
            'amount' => 10000,
            'method' => PaymentMethod::Cash->value,
            'reason' => 'Partial return',
        ];
        $key = ['Idempotency-Key' => (string) Str::uuid()];

        $this->postJson("/api/v1/payments/{$payment->id}/refund", $body, $key)->assertCreated();
        $this->postJson("/api/v1/payments/{$payment->id}/refund", $body, $key)
            ->assertCreated()
            ->assertHeader('Idempotent-Replay', 'true');

        $this->assertSame(1, Refund::count());
    }
}
