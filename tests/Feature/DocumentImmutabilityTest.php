<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DocumentImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create();

        $company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $owner->id,
        ]);

        app(CurrentCompany::class)->set($company);

        $this->contact = Contact::create(['name' => 'A Customer']);
    }

    protected function makeDocument(DocumentStatus $status, float $total = 100): Document
    {
        return Document::create([
            'type' => DocumentType::Invoice,
            'number' => 'INV-2026-'.fake()->unique()->numerify('#####'),
            'contact_id' => $this->contact->id,
            'status' => $status,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'subtotal' => $total,
            'total' => $total,
            'balance' => $total,
        ]);
    }

    public function test_a_draft_can_be_edited(): void
    {
        $document = $this->makeDocument(DocumentStatus::Draft);

        $document->update(['total' => 250, 'subtotal' => 250]);

        $this->assertSame('250.00', $document->fresh()->total);
    }

    public function test_an_issued_document_cannot_have_its_amounts_changed(): void
    {
        $document = $this->makeDocument(DocumentStatus::Issued);

        $this->expectException(RuntimeException::class);

        $document->update(['total' => 999]);
    }

    public function test_an_issued_document_still_accepts_payment_updates(): void
    {
        $document = $this->makeDocument(DocumentStatus::Issued);

        // Recording a payment must remain possible — it is the balance moving,
        // not the document's content changing.
        $document->update([
            'amount_paid' => 100,
            'balance' => 0,
            'status' => DocumentStatus::Paid,
        ]);

        $this->assertSame(DocumentStatus::Paid, $document->fresh()->status);
    }

    public function test_tampering_is_detected_against_the_stored_hash(): void
    {
        $document = $this->makeDocument(DocumentStatus::Draft, 100);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        $document->load('lines');
        $document->forceFill(['content_hash' => hash('sha256', $document->canonicalPayload())])->saveQuietly();

        $this->assertFalse($document->isTampered());

        // Simulate a direct database edit that bypasses the application.
        $document->newQuery()->toBase()->where('id', $document->id)->update(['total' => 999]);

        $reloaded = Document::with('lines')->find($document->id);

        $this->assertTrue($reloaded->isTampered());
    }

    public function test_payment_state_reports_pending_for_an_unpaid_issued_invoice(): void
    {
        $document = $this->makeDocument(DocumentStatus::Sent);

        $this->assertSame('Pending', $document->paymentState()['label']);
    }

    public function test_payment_state_reports_overdue_past_the_due_date(): void
    {
        $document = $this->makeDocument(DocumentStatus::Sent);
        $document->forceFill(['due_date' => now()->subDay()->toDateString()])->saveQuietly();

        $this->assertSame('Overdue', $document->fresh()->paymentState()['label']);
    }
}
