<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentNumberingScheme;
use App\Services\DocumentComposer;

class DocumentNumberingTest extends DocumentsTestCase
{
    public function test_an_unconfigured_business_gets_the_shared_doc_series(): void
    {
        $paper = $this->issue($this->document(['kind' => 'contract', 'reference' => null]));

        $this->assertStringStartsWith('DOC-', $paper->reference);
    }

    public function test_a_kind_specific_scheme_changes_the_prefix(): void
    {
        $this->scheme('contract', 'CONTRACT');

        $paper = $this->issue($this->document(['kind' => 'contract', 'reference' => null]));

        $this->assertStringStartsWith('CONTRACT-', $paper->reference);
    }

    /** Configuring one kind must not change any other kind's numbering. */
    public function test_configuring_one_kind_does_not_affect_another(): void
    {
        $this->scheme('contract', 'CONTRACT');

        $memo = $this->issue($this->document(['kind' => 'memo', 'reference' => null]));

        $this->assertStringStartsWith('DOC-', $memo->reference);
    }

    public function test_a_catch_all_scheme_applies_to_any_kind_with_no_specific_one(): void
    {
        $this->scheme(null, 'BIZ');

        $memo = $this->issue($this->document(['kind' => 'memo', 'reference' => null]));

        $this->assertStringStartsWith('BIZ-', $memo->reference);
    }

    public function test_a_kind_specific_scheme_wins_over_the_catch_all(): void
    {
        $this->scheme(null, 'BIZ');
        $this->scheme('contract', 'CONTRACT');

        $paper = $this->issue($this->document(['kind' => 'contract', 'reference' => null]));

        $this->assertStringStartsWith('CONTRACT-', $paper->reference);
    }

    /** Not every kind needs a number — §25. */
    public function test_a_kind_configured_to_need_no_number_gets_none(): void
    {
        $this->scheme('memo', 'MEMO', requiresNumber: false);

        $memo = $this->issue($this->document(['kind' => 'memo', 'reference' => null]));

        $this->assertNull($memo->reference);
    }

    public function test_a_document_with_no_number_can_still_be_issued(): void
    {
        $this->scheme('memo', 'MEMO', requiresNumber: false);

        $memo = $this->issue($this->document(['kind' => 'memo', 'reference' => null]));

        $this->assertTrue($memo->isIssued());
    }

    public function test_two_kind_specific_schemes_run_independent_sequences(): void
    {
        $this->scheme('contract', 'CONTRACT');
        $this->scheme('nda', 'NDA');

        $first = $this->issue($this->document(['kind' => 'contract', 'reference' => null]));
        $second = $this->issue($this->document(['kind' => 'nda', 'reference' => null]));

        $this->assertStringContainsString('-00001', $first->reference);
        $this->assertStringContainsString('-00001', $second->reference);
    }

    public function test_numbers_increment_within_one_kinds_series(): void
    {
        $this->scheme('contract', 'CONTRACT');

        $first = $this->issue($this->document(['kind' => 'contract', 'reference' => null]));
        $second = $this->issue($this->document(['kind' => 'contract', 'title' => 'Second', 'reference' => null]));

        $this->assertNotSame($first->reference, $second->reference);
    }

    protected function scheme(?string $kind, string $prefix, bool $requiresNumber = true): BusinessDocumentNumberingScheme
    {
        return BusinessDocumentNumberingScheme::create([
            'kind' => $kind,
            'prefix' => $prefix,
            'requires_number' => $requiresNumber,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function issue(\App\Models\BusinessDocument $document): \App\Models\BusinessDocument
    {
        return app(DocumentComposer::class)->issue($document, $this->owner);
    }
}
