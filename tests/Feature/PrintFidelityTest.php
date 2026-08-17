<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PaymentMethod;
use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VerificationToken;
use App\Services\DocumentComposer;
use App\Services\DocumentIssuer;
use App\Services\PaymentRecorder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Print fidelity — every printable artifact must reach paper carrying its
 * verification QR, and must render whatever the data throws at it. The layout
 * itself is CSS, but "the route renders and the QR is in the markup" is the
 * part a regression can silently lose.
 */
class PrintFidelityTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->company = Company::create([
            'slug' => 'acme',
            'name' => 'Acme Ltd',
            'owner_id' => $this->user->id,
            'currency' => 'USD',
            'email' => 'hello@acme.test',
            'address_line1' => '12 Broad Street',
            'city' => 'Lagos',
            'country' => 'NG',
            // Papers is a Growth-plan module and the column defaults to Basic.
            // Stated explicitly now that an Owner no longer bypasses the
            // entitlement check on model-backed actions.
            'plan' => 'growth',
        ]);

        $this->joinCompany($this->company, $this->user);
        $this->user->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);

        $this->contact = Contact::create(['name' => 'Tech Core Ltd', 'balance' => 0]);
    }

    protected function issuedInvoice(int $lineCount = 1): Document
    {
        $document = Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $this->contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'USD',
            'subtotal' => 100 * $lineCount,
            'total' => 100 * $lineCount,
            'balance' => 100 * $lineCount,
        ]);

        foreach (range(1, $lineCount) as $i) {
            DocumentLine::create([
                'document_id' => $document->id,
                'description' => "Line item {$i} — consulting and materials",
                'quantity' => 1,
                'unit_price' => 100,
                'line_total' => 100,
            ]);
        }

        return app(DocumentIssuer::class)->issue($document, $this->user);
    }

    public function test_a_printed_document_carries_its_verification_qr(): void
    {
        $document = $this->issuedInvoice();

        $this->actingAs($this->user)
            ->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('Scan to verify');
    }

    public function test_a_document_with_forty_line_items_still_renders(): void
    {
        $document = $this->issuedInvoice(40);

        $this->actingAs($this->user)
            ->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('Line item 40', false)
            ->assertSee('<svg', false);
    }

    public function test_a_printed_receipt_carries_its_verification_qr(): void
    {
        $invoice = $this->issuedInvoice();
        app(PaymentRecorder::class)->record($invoice, $this->user, 100.0, PaymentMethod::Cash);

        $receipt = Receipt::firstOrFail();

        $this->actingAs($this->user)
            ->get(route('receipts.print', $receipt))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('Scan to verify this receipt');
    }

    /* ------------------------------------------------------------------ *
     * Branding — the company's own colour on its own paperwork
     * ------------------------------------------------------------------ */

    public function test_a_printed_documents_type_label_uses_the_companys_brand_colour(): void
    {
        $this->company->forceFill(['brand_tokens' => ['primary' => '#ff6a00']])->save();
        $document = $this->issuedInvoice();

        $html = $this->actingAs($this->user)->get(route('documents.print', $document))->getContent();

        $this->assertStringContainsString('--brand: #ff6a00;', $html);
    }

    public function test_a_printed_document_falls_back_to_the_platform_palette_with_no_branding_set(): void
    {
        // No explicit brand_tokens/branding — brandToken('primary', …) still
        // resolves through the derived default palette (see BrandPalette),
        // never the caller's hex literal; this only pins that *something*
        // renders, not the platform's specific default shade.
        $document = $this->issuedInvoice();

        $html = $this->actingAs($this->user)->get(route('documents.print', $document))->getContent();

        $this->assertMatchesRegularExpression('/--brand: #[0-9a-f]{6};/', $html);
    }

    public function test_a_printed_receipt_uses_the_companys_brand_colour_on_the_paid_total(): void
    {
        $this->company->forceFill(['brand_tokens' => ['primary' => '#16a34a']])->save();
        $invoice = $this->issuedInvoice();
        app(PaymentRecorder::class)->record($invoice, $this->user, 100.0, PaymentMethod::Cash);
        $receipt = Receipt::firstOrFail();

        $html = $this->actingAs($this->user)->get(route('receipts.print', $receipt))->getContent();

        $this->assertStringContainsString('--brand: #16a34a;', $html);
    }

    public function test_a_generated_watermark_appears_on_a_printed_document_once_switched_on(): void
    {
        $this->company->forceFill([
            'watermark_path' => 'watermarks/test/seal.svg',
            'show_watermark' => true,
        ])->save();
        \Illuminate\Support\Facades\Storage::disk('public')->put('watermarks/test/seal.svg', '<svg></svg>');
        $document = $this->issuedInvoice();

        $html = $this->actingAs($this->user)->get(route('documents.print', $document))->getContent();

        $this->assertStringContainsString('class="watermark"', $html);
        $this->assertStringContainsString('seal.svg', $html);
    }

    public function test_a_configured_but_disabled_watermark_does_not_appear(): void
    {
        $this->company->forceFill([
            'watermark_path' => 'watermarks/test/seal.svg',
            'show_watermark' => false,
        ])->save();
        \Illuminate\Support\Facades\Storage::disk('public')->put('watermarks/test/seal.svg', '<svg></svg>');
        $document = $this->issuedInvoice();

        $html = $this->actingAs($this->user)->get(route('documents.print', $document))->getContent();

        $this->assertStringNotContainsString('seal.svg', $html);
    }

    public function test_a_paper_carries_the_watermark_too_once_switched_on(): void
    {
        $this->company->forceFill([
            'watermark_path' => 'watermarks/test/seal.svg',
            'show_watermark' => true,
        ])->save();
        \Illuminate\Support\Facades\Storage::disk('public')->put('watermarks/test/seal.svg', '<svg></svg>');

        $composer = app(DocumentComposer::class);
        $paper = BusinessDocument::create([
            'template' => 'service_agreement',
            'title' => 'Alpha Builders — supervision',
            'recipient' => 'Alpha Builders Ltd',
            'status' => 'draft',
            'fields' => ['client_name' => 'Alpha Builders Ltd'],
            'body' => $composer->merge(
                'service_agreement',
                ['client_name' => 'Alpha Builders Ltd', 'fee' => '$100'],
                $this->company,
            ),
            'created_by' => $this->user->id,
        ]);
        $paper = $composer->issue($paper, $this->user);

        $html = $this->actingAs($this->user)->get(route('papers.print', $paper))->getContent();

        $this->assertStringContainsString('seal.svg', $html);
    }

    public function test_a_printed_paper_carries_its_verification_qr(): void
    {
        $composer = app(DocumentComposer::class);

        $paper = BusinessDocument::create([
            'template' => 'service_agreement',
            'title' => 'Alpha Builders — supervision',
            'recipient' => 'Alpha Builders Ltd',
            'status' => 'draft',
            'fields' => ['client_name' => 'Alpha Builders Ltd'],
            'body' => $composer->merge(
                'service_agreement',
                ['client_name' => 'Alpha Builders Ltd', 'fee' => '$100'],
                $this->company,
            ),
            'created_by' => $this->user->id,
        ]);
        $paper = $composer->issue($paper, $this->user);

        $this->actingAs($this->user)
            ->get(route('papers.print', $paper))
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('Scan to verify');
    }

    public function test_the_statement_carries_the_company_verification_qr(): void
    {
        $this->issuedInvoice();

        $this->actingAs($this->user)
            ->get('/customers/'.$this->contact->id.'/statement')
            ->assertOk()
            ->assertSee('<svg', false)
            ->assertSee('Scan to verify this business');
    }

    public function test_a_statement_creates_the_company_token_when_none_exists(): void
    {
        // No stationery has ever been printed: the company holds no token yet.
        $this->assertSame(0, VerificationToken::query()
            ->where('subject_type', Company::class)
            ->where('subject_id', $this->company->id)
            ->count());

        $this->actingAs($this->user)
            ->get('/customers/'.$this->contact->id.'/statement')
            ->assertOk()
            ->assertSee('<svg', false);

        $token = VerificationToken::query()
            ->where('subject_type', Company::class)
            ->where('subject_id', $this->company->id)
            ->first();

        $this->assertNotNull($token);

        // A second print reuses the token rather than minting another.
        $this->actingAs($this->user)
            ->get('/customers/'.$this->contact->id.'/statement')
            ->assertOk();

        $this->assertSame(1, VerificationToken::query()
            ->where('subject_type', Company::class)
            ->where('subject_id', $this->company->id)
            ->count());
    }
}
