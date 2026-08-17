<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\DocumentTypeRegistry;
use App\Support\DocumentKinds;

class DocumentTypeRegistryTest extends DocumentsTestCase
{
    protected function tearDown(): void
    {
        DocumentTypeRegistry::flush();

        parent::tearDown();
    }

    public function test_a_module_can_register_a_kind_without_documents_importing_it(): void
    {
        DocumentTypeRegistry::register('vip_agreement', 'VIP agreement', 'Estate');

        $this->assertTrue(DocumentKinds::exists('vip_agreement'));
        $this->assertSame('VIP agreement', DocumentKinds::label('vip_agreement'));
        $this->assertArrayHasKey('Estate', DocumentKinds::groups());
    }

    public function test_a_built_in_kind_is_unaffected_when_nothing_is_registered(): void
    {
        $this->assertSame('Contract', DocumentKinds::label('contract'));
        $this->assertTrue(DocumentKinds::exists('contract'));
    }

    /** A registered kind can never silently reassign what a built-in one means. */
    public function test_a_built_in_key_wins_over_a_registered_one_with_the_same_key(): void
    {
        DocumentTypeRegistry::register('contract', 'Hijacked', 'Nowhere');

        $this->assertSame('Contract', DocumentKinds::label('contract'));
    }

    public function test_an_unregistered_kind_still_falls_back_gracefully(): void
    {
        $this->assertFalse(DocumentKinds::exists('nonexistent_kind'));
        $this->assertSame(DocumentKinds::FALLBACK_LABEL, DocumentKinds::label('nonexistent_kind'));
    }
}
