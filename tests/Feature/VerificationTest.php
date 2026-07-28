<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VerificationScan;
use App\Services\DocumentIssuer;
use App\Services\PaymentRecorder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationTest extends TestCase
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
            'motto' => 'Quality first',
            'owner_id' => $this->user->id,
        ]);

        $this->user->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $this->contact = Contact::create(['name' => 'A Customer']);
    }

    protected function issuedInvoice(float $total = 250): Document
    {
        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => $total,
            'total' => $total,
            'balance' => $total,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->user);
    }

    /**
     * The public page must work with no session and no current company — the
     * scanner is a customer. Clearing the singleton simulates that.
     */
    protected function asPublicVisitor(): void
    {
        app(CurrentCompany::class)->set(null);
    }

    public function test_a_valid_invoice_token_shows_the_verdict_and_details(): void
    {
        $document = $this->issuedInvoice(250);
        $token = $document->verificationToken->token;

        $this->asPublicVisitor();

        $this->get("/v/{$token}")
            ->assertOk()
            ->assertSee('Verified authentic')
            ->assertSee('Acme Ltd')
            ->assertSee($document->number)
            ->assertSee('$250.00')
            ->assertSee('A Customer');
    }

    public function test_scans_are_recorded(): void
    {
        $document = $this->issuedInvoice();
        $token = $document->verificationToken->token;

        $this->asPublicVisitor();
        $this->get("/v/{$token}")->assertOk();
        $this->get("/v/{$token}")->assertOk();

        $this->assertSame(2, VerificationScan::count());
    }

    public function test_a_tampered_document_is_called_out(): void
    {
        $document = $this->issuedInvoice(250);
        $token = $document->verificationToken->token;

        // A direct database edit that bypasses the application.
        Document::query()->toBase()->where('id', $document->id)->update(['total' => 999]);

        $this->asPublicVisitor();

        $this->get("/v/{$token}")
            ->assertOk()
            ->assertSee('Content mismatch')
            ->assertDontSee('Verified authentic');
    }

    public function test_a_voided_document_reads_as_voided_not_invalid(): void
    {
        $document = $this->issuedInvoice();
        $token = $document->verificationToken->token;

        $document->forceFill(['status' => DocumentStatus::Void])->saveQuietly();

        $this->asPublicVisitor();

        $this->get("/v/{$token}")
            ->assertOk()
            ->assertSee('Voided')
            ->assertSee('cancelled by the business');
    }

    public function test_a_revoked_token_reads_as_voided(): void
    {
        $document = $this->issuedInvoice();
        $verification = $document->verificationToken;
        $verification->forceFill(['revoked_at' => now()])->save();

        $this->asPublicVisitor();

        $this->get("/v/{$verification->token}")
            ->assertOk()
            ->assertSee('Voided');
    }

    public function test_an_unknown_token_gets_a_branded_404_verdict(): void
    {
        $this->asPublicVisitor();

        $this->get('/v/AAAAAAAAAAAAAAAAAAAAAA')
            ->assertNotFound()
            ->assertSee('Unknown code');
    }

    public function test_a_receipt_token_shows_amount_method_and_date(): void
    {
        $invoice = $this->issuedInvoice(100);

        app(PaymentRecorder::class)->record($invoice, $this->user, 100.0, PaymentMethod::MobileMoney);

        $receipt = Receipt::firstOrFail();
        $token = $receipt->verificationToken->token;

        $this->asPublicVisitor();

        $this->get("/v/{$token}")
            ->assertOk()
            ->assertSee('Verified authentic')
            ->assertSee($receipt->number)
            ->assertSee('$100.00')
            ->assertSee('Mobile Money');
    }

    public function test_the_qr_endpoint_serves_svg(): void
    {
        $document = $this->issuedInvoice();
        $token = $document->verificationToken->token;

        $this->asPublicVisitor();

        $response = $this->get("/v/{$token}/qr.svg");

        $response->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $response->getContent());
    }

    public function test_the_qr_endpoint_404s_for_unknown_tokens(): void
    {
        $this->asPublicVisitor();

        $this->get('/v/AAAAAAAAAAAAAAAAAAAAAA/qr.svg')->assertNotFound();
    }
}
