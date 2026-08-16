<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentShare;
use App\Models\BusinessDocumentSignature;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\User;
use App\Services\DocumentIssuer;
use App\Support\Pdf;
use Illuminate\Support\Str;
use Tests\Feature\Documents\DocumentsTestCase;

/**
 * Phase 5 — real PDF output.
 *
 * Every print endpoint answers ?format=pdf with an actual file: %PDF magic,
 * an ASCII-safe attachment filename, the same watermarks the browser-print
 * path carries, and the same export-tier audit rows for restricted papers.
 * Tenancy on the public share path is the part that must never regress: a
 * share token produces a PDF of its own tenant's document and nothing else.
 */
class PdfOutputTest extends DocumentsTestCase
{
    public function test_an_invoice_downloads_as_a_pdf_with_the_right_filename(): void
    {
        $invoice = $this->issuedInvoice();

        $response = $this->actingAs($this->owner)
            ->get(route('documents.print', ['document' => $invoice, 'format' => 'pdf']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->streamedContent ?? $response->getContent());

        $disposition = $response->headers->get('Content-Disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString(Pdf::filename($invoice->number, $invoice->type->label()), $disposition);
    }

    public function test_a_draft_papers_pdf_carries_the_draft_watermark(): void
    {
        $paper = $this->document(['status' => 'draft']);

        $response = $this->actingAs($this->owner)
            ->get(route('papers.print', ['paper' => $paper, 'format' => 'pdf']));

        $response->assertOk();
        $binary = $response->getContent();
        $this->assertStringStartsWith('%PDF', $binary);
        $this->assertStringContainsString('DRAFT', $this->pdfText($binary));
    }

    public function test_a_restricted_papers_pdf_writes_an_export_audit_row(): void
    {
        $paper = $this->document(['status' => 'issued', 'issued_at' => now(), 'security' => 'restricted']);

        $this->actingAs($this->owner)
            ->get(route('papers.print', ['paper' => $paper, 'format' => 'pdf']))
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'event' => 'exported',
            'subject_type' => BusinessDocument::class,
            'subject_id' => $paper->id,
        ]);
    }

    public function test_a_customer_statement_downloads_as_a_pdf(): void
    {
        $contact = Contact::create(['name' => 'Tech Core Ltd', 'balance' => 0]);

        $response = $this->actingAs($this->owner)
            ->get(route('customers.statement', ['contact' => $contact, 'format' => 'pdf']));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_share_token_downloads_only_its_own_tenants_pdf(): void
    {
        // A second tenant whose name must never reach this share's PDF.
        $otherOwner = User::factory()->create();
        Company::create([
            'slug' => 'rival-'.Str::lower(Str::random(6)),
            'name' => 'Rival Industries Sarl',
            'owner_id' => $otherOwner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $paper = $this->document(['status' => 'issued', 'issued_at' => now(), 'security' => 'confidential']);
        $share = $paper->shares()->create([
            'created_by' => $this->owner->id,
            'share_token' => BusinessDocumentShare::newShareToken(),
            'allow_download' => true,
        ]);

        // No session, no signed-in user: the token alone resolves the tenant.
        $response = $this->get(route('shares.show', ['token' => $share->share_token, 'format' => 'pdf']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $binary = $response->getContent();
        $this->assertStringStartsWith('%PDF', $binary);

        $text = $this->pdfText($binary);
        $this->assertStringContainsString($this->company->name, $text);
        $this->assertStringNotContainsString('Rival Industries', $text);
        // An external copy is marked as one, never presented as the original.
        $this->assertStringContainsString('COPY', $text);
    }

    public function test_a_share_without_download_permission_gets_no_pdf(): void
    {
        $paper = $this->document(['status' => 'issued', 'issued_at' => now()]);
        $share = $paper->shares()->create([
            'created_by' => $this->owner->id,
            'share_token' => BusinessDocumentShare::newShareToken(),
            'allow_download' => false,
        ]);

        $this->get(route('shares.show', ['token' => $share->share_token, 'format' => 'pdf']))
            ->assertForbidden();
    }

    public function test_a_completed_signature_page_offers_the_pdf(): void
    {
        $paper = $this->document(['status' => 'issued', 'issued_at' => now()]);
        $signature = $paper->signatures()->create([
            'signer_name' => 'Une Signataire',
            'signer_email' => 'signer@example.test',
            'signing_token' => BusinessDocumentSignature::newSigningToken(),
            'status' => 'signed',
            'signed_at' => now(),
            'order' => 1,
        ]);

        $response = $this->get(route('signatures.show', ['token' => $signature->signing_token, 'format' => 'pdf']));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_filenames_are_ascii_safe(): void
    {
        $this->assertSame('FAC-2026-001-Devis-client.pdf', Pdf::filename('FAC-2026-001', 'Devis  cliént'));
        $this->assertSame('document.pdf', Pdf::filename(null, '···'));
    }

    /** An issued invoice, made the way the app makes one. */
    protected function issuedInvoice(): Document
    {
        $contact = Contact::create(['name' => 'Tech Core Ltd', 'balance' => 0]);

        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 100,
            'total' => 100,
            'balance' => 100,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'Consulting and materials',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        return app(DocumentIssuer::class)->issue($document, $this->owner);
    }

    /**
     * Crude text extraction: inflate every FlateDecode stream and return the
     * lot. dompdf writes text through core fonts as literal strings, so
     * asserting on watermark words and tenant names works without a full PDF
     * parser.
     */
    protected function pdfText(string $binary): string
    {
        preg_match_all('/stream\r?\n(.*?)endstream/s', $binary, $matches);

        $text = '';

        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $text .= $inflated !== false ? $inflated : $stream;
        }

        return $text;
    }
}
