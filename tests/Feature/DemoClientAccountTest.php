<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Receipt;
use App\Support\CurrentCompany;
use Database\Seeders\DemoCompanySeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the platform's own demo client — Jude Nshome / OPESWARE llc. — is
 * seeded correctly and works end to end: a real contact, an issued invoice
 * with a real number, a full payment, a real receipt, and print/verification
 * pages that render without error for both.
 */
class DemoClientAccountTest extends TestCase
{
    use RefreshDatabase;

    protected Contact $contact;

    protected Document $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DemoCompanySeeder::class);

        $company = Company::where('slug', 'opesware-technologies')->firstOrFail();
        app(CurrentCompany::class)->set($company);

        $this->contact = Contact::where('email', 'nshomejude@gmail.com')->firstOrFail();
        $this->invoice = Document::where('contact_id', $this->contact->id)
            ->where('type', 'invoice')
            ->firstOrFail();
    }

    public function test_the_contact_is_seeded_with_the_exact_client_details(): void
    {
        $this->assertSame('Jude Nshome', $this->contact->name);
        $this->assertSame('OPESWARE llc.', $this->contact->company_name);
        $this->assertSame('nshomejude@gmail.com', $this->contact->email);
        $this->assertSame('+237670416238', $this->contact->phones[0]);
        $this->assertMatchesRegularExpression('/^\+\d{6,15}$/', $this->contact->phones[0]);
        $this->assertSame('+237670416238', $this->contact->whatsapp);
        $this->assertSame('Douala', $this->contact->address['city']);
        $this->assertSame('Cameroon', $this->contact->address['country']);
    }

    public function test_the_invoice_is_issued_paid_and_annual(): void
    {
        $this->assertSame(DocumentStatus::Paid, $this->invoice->status);
        $this->assertStringStartsWith('INV-', $this->invoice->number);
        $this->assertSame('XAF', $this->invoice->currency);
        $this->assertSame('30000.00', (string) $this->invoice->total);
        $this->assertSame('0.00', (string) $this->invoice->balance);
        $this->assertNotNull($this->invoice->verificationToken);

        $line = $this->invoice->lines()->firstOrFail();
        $this->assertStringContainsString('OPES 360', $line->description);
        $this->assertSame(1, (int) $line->quantity);
        $this->assertSame('30000.00', (string) $line->unit_price);
    }

    public function test_a_payment_and_receipt_exist_for_the_invoice(): void
    {
        $payment = Payment::where('contact_id', $this->contact->id)->firstOrFail();
        $this->assertSame('30000.00', (string) $payment->amount);

        $receipt = Receipt::where('payment_id', $payment->id)->firstOrFail();
        $this->assertStringStartsWith('RCP-', $receipt->number);
        $this->assertSame('30000.00', (string) $receipt->total);
        $this->assertNotNull($receipt->verificationToken);
    }

    public function test_the_invoice_print_page_renders_the_client(): void
    {
        $owner = $this->invoice->company->owner;
        $this->actingAs($owner);

        $this->get("/documents/{$this->invoice->id}/print")
            ->assertOk()
            ->assertSee('OPESWARE llc.')
            ->assertSee($this->invoice->number);
    }

    public function test_the_invoice_verification_page_renders_publicly(): void
    {
        $token = $this->invoice->verificationToken->token;
        app(CurrentCompany::class)->set(null);

        $this->get("/v/{$token}")
            ->assertOk()
            ->assertSee('Verified authentic')
            ->assertSee('OPESWARE llc.')
            ->assertSee($this->invoice->number);
    }

    public function test_the_receipt_prints_in_a4_format_without_error(): void
    {
        $receipt = Receipt::where('contact_id', $this->contact->id)->firstOrFail();
        $this->assertSame('a4', $receipt->format);

        $owner = $this->invoice->company->owner;
        $this->actingAs($owner);

        $this->get("/receipts/{$receipt->id}/print")
            ->assertOk()
            ->assertSee('OPESWARE llc.')
            ->assertSee($receipt->number)
            ->assertSee('FCFA30,000');
    }

    public function test_the_receipt_prints_in_thermal80_format_without_error(): void
    {
        // The seeded receipt is A4; a thermal80 copy of the same payment proves
        // the same print view renders cleanly at the other page width too.
        $seeded = Receipt::where('contact_id', $this->contact->id)->firstOrFail();

        $thermal = $seeded->replicate();
        $thermal->format = 'thermal80';
        $thermal->number = 'RCP-2026-THERM01';
        $thermal->save();

        $owner = $this->invoice->company->owner;
        $this->actingAs($owner);

        $this->get("/receipts/{$thermal->id}/print")
            ->assertOk()
            ->assertSee('OPESWARE llc.')
            ->assertSee($thermal->number)
            ->assertSee('FCFA30,000');
    }

    public function test_the_receipt_verification_page_renders_publicly(): void
    {
        $receipt = Receipt::where('contact_id', $this->contact->id)->firstOrFail();
        $token = $receipt->verificationToken->token;
        app(CurrentCompany::class)->set(null);

        $this->get("/v/{$token}")
            ->assertOk()
            ->assertSee('Verified authentic')
            ->assertSee($receipt->number);
    }

    public function test_seeding_the_demo_company_twice_stays_idempotent(): void
    {
        $this->seed(DemoCompanySeeder::class);

        $reseeded = Contact::where('email', 'nshomejude@gmail.com')->firstOrFail();
        $this->assertSame(1, Contact::where('email', 'nshomejude@gmail.com')->count());
        $this->assertSame(1, Document::where('contact_id', $reseeded->id)->where('type', 'invoice')->count());
    }
}
