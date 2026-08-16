<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentChecklist;
use App\Models\BusinessDocumentPackage;
use App\Models\Contact;
use App\Services\Documents\DocumentDossiers;
use App\Services\Documents\DocumentLinker;
use RuntimeException;

class DossierAndPackageTest extends DocumentsTestCase
{
    // ── Dossiers (§36–37) ────────────────────────────────────────────────

    public function test_a_dossier_groups_a_records_documents_by_kind(): void
    {
        $customer = $this->customer();
        $this->linkTo($customer, $this->document(['kind' => 'contract', 'title' => 'The contract']));
        $this->linkTo($customer, $this->document(['kind' => 'contract', 'title' => 'An amendment']));
        $this->linkTo($customer, $this->document(['kind' => 'letter', 'title' => 'A letter']));

        $dossier = $this->dossiers()->forRecord($customer);

        $this->assertSame(3, $dossier['total']);
        $this->assertCount(2, $dossier['groups']['contract']['documents']);
        $this->assertCount(1, $dossier['groups']['letter']['documents']);
        $this->assertSame('Contract', $dossier['groups']['contract']['label']);
    }

    public function test_a_record_with_no_documents_has_an_empty_dossier(): void
    {
        $dossier = $this->dossiers()->forRecord($this->customer());

        $this->assertSame(0, $dossier['total']);
        $this->assertSame([], $dossier['groups']);
    }

    /** Documents with no kind still appear, grouped under the fallback. */
    public function test_an_unkinded_document_still_appears_in_the_dossier(): void
    {
        $customer = $this->customer();
        $this->linkTo($customer, $this->document(['kind' => null, 'title' => 'Something']));

        $dossier = $this->dossiers()->forRecord($customer);

        $this->assertSame(1, $dossier['total']);
        $this->assertArrayHasKey('document', $dossier['groups']);
    }

    /** A dossier is a question, not a stored row — nothing to drift. */
    public function test_the_dossier_reflects_a_link_being_removed(): void
    {
        $customer = $this->customer();
        $doc = $this->document(['kind' => 'contract']);
        $this->linkTo($customer, $doc);

        app(DocumentLinker::class)->detach($doc, $customer);

        $this->assertSame(0, $this->dossiers()->forRecord($customer)['total']);
    }

    // ── Packages (§38) ───────────────────────────────────────────────────

    public function test_documents_can_be_added_to_a_package(): void
    {
        $package = $this->package();
        $this->dossiers()->addToPackage($package, $this->document(['title' => 'One']));
        $this->dossiers()->addToPackage($package, $this->document(['title' => 'Two']));

        $this->assertSame(2, $package->fresh()->documents()->count());
    }

    public function test_adding_the_same_document_twice_is_idempotent(): void
    {
        $package = $this->package();
        $doc = $this->document();

        $this->dossiers()->addToPackage($package, $doc);
        $this->dossiers()->addToPackage($package->fresh(), $doc);

        $this->assertSame(1, $package->fresh()->documents()->count());
    }

    /** A package is an arrangement — removing from it never deletes anything. */
    public function test_removing_from_a_package_leaves_the_document_alone(): void
    {
        $package = $this->package();
        $doc = $this->document();
        $this->dossiers()->addToPackage($package, $doc);

        $this->dossiers()->removeFromPackage($package->fresh(), $doc);

        $this->assertSame(0, $package->fresh()->documents()->count());
        $this->assertNotNull($doc->fresh());
    }

    public function test_deleting_a_package_deletes_none_of_its_documents(): void
    {
        $package = $this->package();
        $doc = $this->document();
        $this->dossiers()->addToPackage($package, $doc);

        $package->forceDelete();

        $this->assertNotNull($doc->fresh());
    }

    public function test_a_closed_package_refuses_new_documents(): void
    {
        $package = $this->package();
        $this->dossiers()->closePackage($package);

        $this->expectException(RuntimeException::class);

        $this->dossiers()->addToPackage($package->fresh(), $this->document());
    }

    public function test_a_reopened_package_accepts_documents_again(): void
    {
        $package = $this->package();
        $this->dossiers()->closePackage($package);
        $this->dossiers()->reopenPackage($package->fresh());

        $this->dossiers()->addToPackage($package->fresh(), $this->document());

        $this->assertSame(1, $package->fresh()->documents()->count());
    }

    // ── Checklists (§40) ─────────────────────────────────────────────────

    public function test_a_checklist_reports_what_is_missing(): void
    {
        $customer = $this->customer();
        $checklist = $this->checklist(['contract', 'certificate']);
        $this->linkTo($customer, $this->document(['kind' => 'contract']));

        $status = $this->dossiers()->checklistStatus($checklist, $customer);

        $this->assertFalse($status['complete']);
        $this->assertSame(['contract'], $status['satisfied']);
        $this->assertSame(['certificate'], $status['missing']);
    }

    public function test_a_checklist_is_complete_once_every_kind_is_present(): void
    {
        $customer = $this->customer();
        $checklist = $this->checklist(['contract', 'certificate']);
        $this->linkTo($customer, $this->document(['kind' => 'contract']));
        $this->linkTo($customer, $this->document(['kind' => 'certificate']));

        $status = $this->dossiers()->checklistStatus($checklist, $customer);

        $this->assertTrue($status['complete']);
        $this->assertSame([], $status['missing']);
    }

    /** Computed from the documents themselves — no stored flag to drift. */
    public function test_removing_a_document_makes_a_complete_checklist_incomplete_again(): void
    {
        $customer = $this->customer();
        $checklist = $this->checklist(['contract']);
        $doc = $this->document(['kind' => 'contract']);
        $this->linkTo($customer, $doc);

        $this->assertTrue($this->dossiers()->checklistStatus($checklist, $customer)['complete']);

        app(DocumentLinker::class)->detach($doc, $customer);

        $this->assertFalse($this->dossiers()->checklistStatus($checklist, $customer)['complete']);
    }

    public function test_checklists_are_found_by_the_record_type_they_apply_to(): void
    {
        $this->checklist(['contract']);

        $found = $this->dossiers()->checklistsFor($this->customer());

        $this->assertSame(1, $found->count());
    }

    protected function customer(): Contact
    {
        return Contact::create(['name' => 'A Customer', 'balance' => 0]);
    }

    protected function linkTo(Contact $customer, \App\Models\BusinessDocument $document): void
    {
        app(DocumentLinker::class)->attach($document, $customer, 'about', $this->owner);
    }

    protected function package(): BusinessDocumentPackage
    {
        return BusinessDocumentPackage::create([
            'name' => 'Annual Audit Package 2026',
            'status' => 'open',
            'created_by' => $this->owner->id,
        ]);
    }

    protected function checklist(array $kinds): BusinessDocumentChecklist
    {
        return BusinessDocumentChecklist::create([
            'name' => 'Customer onboarding',
            'subject_type' => Contact::class,
            'required_kinds' => $kinds,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function dossiers(): DocumentDossiers
    {
        return app(DocumentDossiers::class);
    }
}
