<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Documents\Show;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Receipt;
use App\Models\User;
use App\Services\PaymentRecorder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PaymentRecordingTest extends TestCase
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
        ]);

        $this->user->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $this->contact = Contact::create(['name' => 'A Customer', 'balance' => 500]);
    }

    protected function makeInvoice(float $total = 500): Document
    {
        return Document::create([
            'type' => DocumentType::Invoice,
            'number' => 'INV-T-'.fake()->unique()->numerify('####'),
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Issued,
            'issue_date' => now()->toDateString(),
            'subtotal' => $total,
            'total' => $total,
            'amount_paid' => 0,
            'balance' => $total,
        ]);
    }

    public function test_a_full_payment_marks_the_invoice_paid_and_issues_a_receipt(): void
    {
        $invoice = $this->makeInvoice(500);
        $year = now()->format('Y');

        $payment = app(PaymentRecorder::class)->record(
            $invoice, $this->user, 500.0, PaymentMethod::Cash
        );

        $invoice->refresh();

        $this->assertSame(DocumentStatus::Paid, $invoice->status);
        $this->assertSame('0.00', $invoice->balance);
        $this->assertSame('500.00', $invoice->amount_paid);

        $receipt = Receipt::firstOrFail();
        $this->assertSame("RCP-{$year}-00001", $receipt->number);
        $this->assertSame($payment->id, $receipt->payment_id);
        $this->assertNotNull($receipt->content_hash);
        $this->assertNotNull($receipt->verification_token_id);

        // The customer's cached rollup follows the money.
        $this->assertSame('0.00', $this->contact->fresh()->balance);
    }

    public function test_a_part_payment_marks_the_invoice_partial(): void
    {
        $invoice = $this->makeInvoice(500);

        app(PaymentRecorder::class)->record($invoice, $this->user, 200.0, PaymentMethod::MobileMoney);

        $invoice->refresh();

        $this->assertSame(DocumentStatus::Partial, $invoice->status);
        $this->assertSame('300.00', $invoice->balance);
        $this->assertSame('Partial', $invoice->paymentState()['label']);
    }

    public function test_receipt_numbers_are_sequential_across_payments(): void
    {
        $year = now()->format('Y');

        app(PaymentRecorder::class)->record($this->makeInvoice(100), $this->user, 100.0, PaymentMethod::Cash);
        app(PaymentRecorder::class)->record($this->makeInvoice(100), $this->user, 100.0, PaymentMethod::Card);

        $this->assertSame(
            ["RCP-{$year}-00001", "RCP-{$year}-00002"],
            Receipt::orderBy('number')->pluck('number')->all(),
        );
    }

    public function test_overpayment_is_refused(): void
    {
        $invoice = $this->makeInvoice(100);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds the outstanding balance/');

        app(PaymentRecorder::class)->record($invoice, $this->user, 150.0, PaymentMethod::Cash);
    }

    public function test_payments_cannot_be_recorded_against_drafts(): void
    {
        $draft = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Draft,
            'total' => 100,
            'balance' => 100,
        ]);

        $this->expectException(RuntimeException::class);

        app(PaymentRecorder::class)->record($draft, $this->user, 100.0, PaymentMethod::Cash);
    }

    public function test_the_document_page_records_a_payment_end_to_end(): void
    {
        $invoice = $this->makeInvoice(500);

        Livewire::actingAs($this->user)
            ->test(Show::class, ['document' => $invoice])
            ->call('openPayment')
            ->assertSet('amount', '500.00')   // prefilled with the balance
            ->set('amount', '500')
            ->set('method', 'mobile_money')
            ->call('recordPayment')
            ->assertHasNoErrors()
            ->assertSee('Paid')
            ->assertSee('RCP-');

        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);
    }

    public function test_the_document_page_surfaces_service_errors_in_place(): void
    {
        $invoice = $this->makeInvoice(100);

        Livewire::actingAs($this->user)
            ->test(Show::class, ['document' => $invoice])
            ->call('openPayment')
            ->set('amount', '999')
            ->call('recordPayment')
            ->assertHasErrors(['amount']);

        $this->assertSame(DocumentStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(0, Receipt::count());
    }
}
