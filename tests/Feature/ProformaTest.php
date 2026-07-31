<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Services\DocumentConverter;
use App\Services\DocumentIssuer;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proforma invoices exist end-to-end in the sales engine; this pins the
 * behaviour that makes them a distinct type rather than a renamed invoice —
 * they carry no receivable, and they graduate into a real invoice.
 */
class ProformaTest extends TestCase
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

        $this->contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);
    }

    protected function draftProforma(float $total = 500): Document
    {
        $document = Document::create([
            'type' => DocumentType::Proforma,
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
            'description' => 'Advance works',
            'quantity' => 1,
            'unit_price' => $total,
            'line_total' => $total,
        ]);

        return $document;
    }

    public function test_the_create_page_offers_proforma(): void
    {
        $this->actingAs($this->user)
            ->get('/documents/create?type=proforma')
            ->assertOk()
            ->assertSee('Proforma');
    }

    public function test_the_quick_actions_include_proforma(): void
    {
        $labels = collect(config('opes.quick_actions'))->pluck('label');

        $this->assertTrue($labels->contains('New Proforma'));
    }

    public function test_an_issued_proforma_gets_a_pro_number_and_no_receivable(): void
    {
        $document = app(DocumentIssuer::class)->issue($this->draftProforma(), $this->user);

        $this->assertStringStartsWith('PRO-', $document->number);
        $this->assertFalse($document->type->isReceivable());
        // The customer owes nothing until a real invoice exists.
        $this->assertSame(0.0, (float) $this->contact->fresh()->balance);
    }

    public function test_a_proforma_converts_into_an_invoice(): void
    {
        $proforma = app(DocumentIssuer::class)->issue($this->draftProforma(), $this->user);

        $converter = app(DocumentConverter::class);
        $this->assertTrue($converter->canConvert($proforma));
        $this->assertSame(DocumentType::Invoice, $converter->targetType($proforma));

        $invoice = $converter->convert($proforma, $this->user);

        $this->assertSame(DocumentType::Invoice, $invoice->type);
        $this->assertSame((float) $proforma->total, (float) $invoice->total);
    }
}
