<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentLine;
use App\Models\Role;
use App\Models\User;
use App\Services\DocumentIssuer;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The letterhead on printed documents.
 *
 * The behaviour that matters is not that a logo appears — it is that it is
 * *read* rather than *stored*. A business that changes its logo means every
 * document it prints from then on, including ones issued years ago, and a
 * letterhead copied onto each document at issue time could not do that.
 *
 * The counterpart matters just as much: changing the letterhead must not
 * disturb what the QR verifies. Presentation is live, content is frozen.
 */
class LetterheadTest extends TestCase
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
            'logo_path' => 'logos/original-mark.png',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();

        app(CurrentCompany::class)->set($this->company);
    }

    protected function issuedQuotation(): Document
    {
        $contact = Contact::create(['type' => 'customer', 'name' => 'Nodai Felix']);

        $document = Document::create([
            'type' => DocumentType::Quotation,
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Draft,
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 900000,
            'tax_total' => 0,
            'total' => 900000,
            'amount_paid' => 0,
            'balance' => 900000,
            'created_by' => $this->owner->id,
        ]);

        DocumentLine::create([
            'document_id' => $document->id,
            'description' => 'SaaS Platform — Year 1',
            'quantity' => 1,
            'unit' => 'unit',
            'unit_price' => 900000,
            'tax_amount' => 0,
            'line_total' => 900000,
            'sort_order' => 0,
        ]);

        return app(DocumentIssuer::class)->issue($document->fresh(), $this->owner);
    }

    public function test_a_quotation_prints_with_the_company_logo(): void
    {
        $document = $this->issuedQuotation();

        $this->actingAs($this->owner)
            ->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('<img class="letterhead-logo"', false)
            ->assertSee('original-mark.png', false);
    }

    /**
     * The point of the whole feature: the letterhead is read at render time,
     * so changing it reaches documents that were issued before the change.
     */
    public function test_changing_the_logo_reaches_documents_already_issued(): void
    {
        $document = $this->issuedQuotation();

        $this->company->forceFill(['logo_path' => 'logos/new-mark.png'])->saveQuietly();

        $this->actingAs($this->owner)
            ->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('new-mark.png', false)
            ->assertDontSee('original-mark.png', false);
    }

    /** The same applies to the rest of the letterhead, not only the logo. */
    public function test_changing_the_address_reaches_documents_already_issued(): void
    {
        $document = $this->issuedQuotation();

        $this->company->forceFill([
            'address_line1' => 'Rue Manga Bell',
            'city' => 'Douala',
        ])->saveQuietly();

        $this->actingAs($this->owner)
            ->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('Rue Manga Bell');
    }

    /**
     * And the counterpart: presentation is live, content is frozen. A changed
     * letterhead must leave the hash the QR verifies against alone, or every
     * printed copy in circulation would stop verifying the moment somebody
     * uploaded a new logo.
     */
    public function test_changing_the_letterhead_does_not_break_verification(): void
    {
        $document = $this->issuedQuotation();
        $hash = $document->content_hash;

        $this->company->forceFill([
            'logo_path' => 'logos/new-mark.png',
            'address_line1' => 'Somewhere else entirely',
        ])->saveQuietly();

        $document->refresh();

        $this->assertSame($hash, $document->content_hash);
        $this->assertSame($hash, hash('sha256', $document->canonicalPayload()));
        $this->assertFalse($document->isTampered());
    }

    public function test_a_business_with_no_logo_still_prints(): void
    {
        $this->company->forceFill(['logo_path' => null])->saveQuietly();

        $document = $this->issuedQuotation();

        // Asserted against the <img>, not the class name: the stylesheet
        // defines `.letterhead-logo` whether or not one is rendered.
        $this->actingAs($this->owner)
            ->get(route('documents.print', $document))
            ->assertOk()
            ->assertSee('Acme Sarl')
            ->assertDontSee('<img class="letterhead-logo"', false);
    }

    public function test_the_logo_url_is_null_without_one(): void
    {
        $this->company->forceFill(['logo_path' => null])->saveQuietly();

        $this->assertNull($this->company->fresh()->logoUrl());
    }
}
