<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Livewire\Customers\Form as CustomerForm;
use App\Livewire\Documents\Show;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Services\PaymentRecorder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class DocumentLifecycleTest extends TestCase
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

        $this->user->forceFill(['current_company_id' => $company->id])->save();
        app(CurrentCompany::class)->set($company);

        $this->contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);
    }

    protected function issued(DocumentType $type, float $total = 400): Document
    {
        $document = Document::create([
            'type' => $type,
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
            'description' => 'Site works',
            'quantity' => 2,
            'unit_price' => $total / 2,
            'line_total' => $total,
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->user);
    }

    public function test_a_quotation_converts_into_an_issued_invoice(): void
    {
        $quotation = $this->issued(DocumentType::Quotation, 400);
        $year = now()->format('Y');

        $invoice = app(DocumentConverter::class)->convert($quotation, $this->user);

        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame(DocumentStatus::Issued, $invoice->status);
        $this->assertSame("INV-{$year}-00001", $invoice->number);
        $this->assertSame('400.00', $invoice->total);
        $this->assertSame($quotation->id, $invoice->parent_document_id);

        // Lines are copied, so the invoice stands on its own.
        $this->assertCount(1, $invoice->lines);
        $this->assertSame('Site works', $invoice->lines->first()->description);

        // The source is untouched apart from being marked accepted.
        $this->assertSame(DocumentStatus::Accepted, $quotation->fresh()->status);
        $this->assertSame('400.00', $quotation->fresh()->total);
    }

    public function test_a_quotation_cannot_be_converted_twice(): void
    {
        $quotation = $this->issued(DocumentType::Quotation);
        $converter = app(DocumentConverter::class);

        $converter->convert($quotation, $this->user);

        $this->assertFalse($converter->canConvert($quotation->fresh()));

        $this->expectException(RuntimeException::class);
        $converter->convert($quotation->fresh(), $this->user);
    }

    public function test_voiding_revokes_the_token_so_a_scan_reports_voided(): void
    {
        $invoice = $this->issued(DocumentType::Invoice, 400);
        $token = $invoice->verificationToken->token;

        app(DocumentConverter::class)->void($invoice, $this->user, 'Duplicate');

        $this->assertSame(DocumentStatus::Void, $invoice->fresh()->status);
        $this->assertSame('0.00', $invoice->fresh()->balance);
        $this->assertNotNull($invoice->verificationToken->fresh()->revoked_at);

        app(CurrentCompany::class)->set(null);

        $this->get("/v/{$token}")->assertOk()->assertSee('Voided');
    }

    public function test_voiding_is_refused_once_money_has_been_taken(): void
    {
        $invoice = $this->issued(DocumentType::Invoice, 400);

        app(PaymentRecorder::class)->record($invoice, $this->user, 100.0, PaymentMethod::Cash);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/payments against it/');

        app(DocumentConverter::class)->void($invoice->fresh(), $this->user);
    }

    public function test_the_document_page_converts_and_voids(): void
    {
        $quotation = $this->issued(DocumentType::Quotation);

        Livewire::actingAs($this->user)
            ->test(Show::class, ['document' => $quotation])
            ->assertSee('Convert to Invoice')
            ->call('convert')
            ->assertRedirect();

        $invoice = Document::query()->invoices()->firstOrFail();

        Livewire::actingAs($this->user)
            ->test(Show::class, ['document' => $invoice])
            ->call('openVoid')
            ->assertSet('voidingOpen', true)
            ->set('voidReason', 'Cancelled order')
            ->call('voidDocument')
            ->assertHasNoErrors()
            ->assertSee('Void');

        $this->assertSame(DocumentStatus::Void, $invoice->fresh()->status);
    }

    public function test_the_void_panel_surfaces_the_paid_guard_in_place(): void
    {
        $invoice = $this->issued(DocumentType::Invoice, 400);
        app(PaymentRecorder::class)->record($invoice, $this->user, 400.0, PaymentMethod::Cash);

        Livewire::actingAs($this->user)
            ->test(Show::class, ['document' => $invoice->fresh()])
            ->call('openVoid')
            ->call('voidDocument')
            ->assertHasErrors(['voidReason']);

        $this->assertSame(DocumentStatus::Paid, $invoice->fresh()->status);
    }

    public function test_the_customer_form_creates_a_contact(): void
    {
        Livewire::actingAs($this->user)
            ->test(CustomerForm::class, ['type' => 'customer'])
            ->set('form.name', 'Blue Sky Ltd')
            ->set('form.email', 'HELLO@BlueSky.test')
            ->set('form.phone', '+234 700 000 1111')
            ->set('form.payment_terms_days', '30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $contact = Contact::where('name', 'Blue Sky Ltd')->firstOrFail();

        $this->assertSame('customer', $contact->type);
        $this->assertSame('hello@bluesky.test', $contact->email);   // normalised
        $this->assertSame(['+234 700 000 1111'], $contact->phones);
        $this->assertSame(30, $contact->payment_terms_days);
    }

    public function test_the_customer_form_requires_a_name(): void
    {
        Livewire::actingAs($this->user)
            ->test(CustomerForm::class)
            ->set('form.email', 'no-name@example.com')
            ->call('save')
            ->assertHasErrors(['form.name']);
    }

    public function test_the_offline_shell_page_is_public(): void
    {
        // Precached by the service worker, so it must render without a session.
        $this->get('/offline')->assertOk()->assertSee("You're offline", false);
    }
}
