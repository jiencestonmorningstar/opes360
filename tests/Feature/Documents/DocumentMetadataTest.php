<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\DocumentKinds;
use Illuminate\Support\Str;

class DocumentMetadataTest extends DocumentsTestCase
{
    public function test_a_document_carries_its_classification(): void
    {
        $doc = $this->document(['kind' => 'contract', 'security' => 'confidential']);

        $this->assertSame('contract', $doc->kind);
        $this->assertSame('Contract', $doc->kindLabel());
        $this->assertTrue($doc->isConfidential());
    }

    public function test_an_unknown_kind_falls_back_rather_than_breaking_a_list(): void
    {
        $this->assertSame('Document', $this->document(['kind' => 'not-a-kind'])->kindLabel());
        $this->assertSame('Document', $this->document(['kind' => null])->kindLabel());
    }

    public function test_only_confidential_and_above_count_as_confidential(): void
    {
        $this->assertFalse($this->document(['security' => 'public'])->isConfidential());
        $this->assertFalse($this->document(['security' => 'internal'])->isConfidential());
        $this->assertFalse($this->document(['security' => null])->isConfidential());
        $this->assertTrue($this->document(['security' => 'confidential'])->isConfidential());
        $this->assertTrue($this->document(['security' => 'restricted'])->isConfidential());
    }

    public function test_tags_round_trip(): void
    {
        $doc = $this->document(['tags' => ['contract', '2026']]);

        $this->assertSame(['contract', '2026'], $doc->fresh()->tags);
    }

    public function test_an_expiring_document_can_be_found(): void
    {
        $this->document(['expires_on' => now()->addDays(10)->toDateString()]);
        $this->document(['expires_on' => now()->addYear()->toDateString()]);
        $this->document();

        $this->assertSame(1, BusinessDocument::expiringWithin(30)->count());
    }

    /**
     * Expiring is a prompt to act before a deadline. Mixing in the deadlines
     * already missed makes it a list people stop opening.
     */
    public function test_an_already_expired_document_is_not_reported_as_expiring(): void
    {
        $this->document(['expires_on' => now()->subDay()->toDateString()]);

        $this->assertSame(0, BusinessDocument::expiringWithin(30)->count());
        $this->assertSame(1, BusinessDocument::expired()->count());
    }

    public function test_a_document_expiring_today_is_still_expiring_not_expired(): void
    {
        $this->document(['expires_on' => now()->toDateString()]);

        $this->assertSame(1, BusinessDocument::expiringWithin(30)->count());
        $this->assertSame(0, BusinessDocument::expired()->count());
    }

    public function test_every_catalogued_kind_has_a_label_and_a_group(): void
    {
        $this->assertNotEmpty(DocumentKinds::all());

        foreach (DocumentKinds::all() as $key => $kind) {
            $this->assertArrayHasKey('label', $kind, $key);
            $this->assertArrayHasKey('group', $kind, $key);
            $this->assertNotSame('', trim($kind['label']), $key);
        }
    }

    /**
     * The rule the whole module rests on: Documents never becomes a second
     * source of truth for money.
     */
    public function test_no_erp_transaction_type_is_a_document_kind(): void
    {
        foreach (['invoice', 'quotation', 'proforma', 'receipt', 'purchase_order', 'credit_note'] as $forbidden) {
            $this->assertArrayNotHasKey(
                $forbidden,
                DocumentKinds::all(),
                "{$forbidden} belongs to the sales module, not to Documents",
            );
        }
    }

    /** Existing rows predate every one of these columns. */
    public function test_a_row_with_no_metadata_still_works(): void
    {
        $doc = $this->document();
        $doc->forceFill(['kind' => null, 'security' => null, 'tags' => null])->save();

        $fresh = $doc->fresh();

        $this->assertSame('Document', $fresh->kindLabel());
        $this->assertFalse($fresh->isConfidential());
        $this->assertNull($fresh->tags);
    }

    // ── Filing an issued document ────────────────────────────────────────

    /**
     * An issued document is immutable, and it must stay that way.
     */
    public function test_an_issued_document_still_refuses_content_edits(): void
    {
        $doc = $this->document(['status' => 'issued', 'issued_at' => now()]);

        $this->expectException(\RuntimeException::class);

        $doc->forceFill(['body' => 'Rewritten after signature.'])->save();
    }

    /**
     * But filing is not editing. Putting a signed contract in a folder or
     * tagging it changes where the business keeps it, not what it says —
     * and refusing that would make the module useless for exactly the
     * documents that most need managing.
     */
    public function test_an_issued_document_can_still_be_filed(): void
    {
        $doc = $this->document(['status' => 'issued', 'issued_at' => now()]);

        $doc->forceFill([
            'kind' => 'contract',
            'security' => 'confidential',
            'tags' => ['signed', '2026'],
            'owner_id' => $this->owner->id,
            'expires_on' => now()->addYear()->toDateString(),
        ])->save();

        $this->assertSame('contract', $doc->fresh()->kind);
        $this->assertSame(['signed', '2026'], $doc->fresh()->tags);
    }

    /**
     * And filing must not break tamper detection. None of the filing columns
     * appear in canonicalPayload(), so the hash taken at issue still validates.
     */
    public function test_filing_an_issued_document_leaves_its_hash_valid(): void
    {
        $doc = $this->document(['status' => 'draft']);

        // Issuing stamps the status, the time and the hash in one save — the
        // immutability guard blocks a second one, which is the point of it.
        $doc->issued_at = now();
        $doc->forceFill([
            'status' => 'issued',
            'content_hash' => hash('sha256', $doc->canonicalPayload()),
        ])->save();

        $this->assertFalse($doc->fresh()->isTampered(), 'hash should be valid before filing');

        $doc->fresh()->forceFill([
            'kind' => 'contract',
            'tags' => ['signed'],
            'security' => 'restricted',
        ])->save();

        $this->assertFalse(
            $doc->fresh()->isTampered(),
            'filing changed the hash — a filing column has leaked into canonicalPayload()',
        );
    }

    public function test_another_companys_documents_are_invisible(): void
    {
        $this->document(['kind' => 'contract']);

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => User::factory()->create()->id,
            'currency' => 'XAF', 'plan' => 'business', 'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, BusinessDocument::count());
    }
}
