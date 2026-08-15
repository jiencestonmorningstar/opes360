<?php

namespace Tests\Feature\Documents;

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\BusinessDocumentRelation;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentLinker;
use App\Support\CurrentCompany;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentRelationTest extends DocumentsTestCase
{
    protected function linker(): DocumentLinker
    {
        return app(DocumentLinker::class);
    }

    protected function customer(string $name = 'Un Client'): Contact
    {
        return Contact::create(['name' => $name, 'balance' => 0]);
    }

    protected function invoice(Contact $contact): Document
    {
        return Document::create([
            'type' => DocumentType::Invoice,
            'contact_id' => $contact->id,
            'status' => DocumentStatus::Issued->value,
            'number' => 'INV-'.Str::upper(Str::random(5)),
            'issue_date' => now()->toDateString(),
            'currency' => 'XAF',
            'subtotal' => 100000, 'discount_total' => 0, 'tax_total' => 0,
            'total' => 100000, 'amount_paid' => 0, 'balance' => 100000,
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_a_document_attaches_to_a_customer(): void
    {
        $customer = $this->customer();
        $doc = $this->document(['kind' => 'contract']);

        $this->linker()->attach($doc, $customer, 'about', $this->owner);

        $found = $this->linker()->documentsFor($customer);

        $this->assertCount(1, $found);
        $this->assertSame($doc->id, $found->first()->id);
    }

    public function test_a_document_attaches_to_an_invoice(): void
    {
        $invoice = $this->invoice($this->customer());
        $doc = $this->document(['kind' => 'supporting_document']);

        $this->linker()->attach($doc, $invoice, 'supporting', $this->owner);

        $this->assertCount(1, $this->linker()->documentsFor($invoice));
    }

    public function test_one_document_can_carry_many_relations(): void
    {
        $customer = $this->customer();
        $invoice = $this->invoice($customer);
        $doc = $this->document();

        $this->linker()->attach($doc, $customer);
        $this->linker()->attach($doc, $invoice, 'supporting');

        $this->assertCount(2, $this->linker()->relationsOf($doc));
    }

    /** Two people attaching the same thing must produce one link, not two. */
    public function test_attaching_twice_is_idempotent(): void
    {
        $customer = $this->customer();
        $doc = $this->document();

        $first = $this->linker()->attach($doc, $customer);
        $second = $this->linker()->attach($doc, $customer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, BusinessDocumentRelation::count());
    }

    /** The same document may be both "about" and "supporting" one record. */
    public function test_a_different_role_is_a_different_link(): void
    {
        $customer = $this->customer();
        $doc = $this->document();

        $this->linker()->attach($doc, $customer, 'about');
        $this->linker()->attach($doc, $customer, 'supporting');

        $this->assertSame(2, BusinessDocumentRelation::count());
        $this->assertCount(1, $this->linker()->documentsFor($customer, 'about'));
    }

    public function test_an_unknown_role_falls_back_rather_than_erroring(): void
    {
        $relation = $this->linker()->attach($this->document(), $this->customer(), 'nonsense');

        $this->assertSame('about', $relation->role);
    }

    public function test_detaching_leaves_the_erp_record_untouched(): void
    {
        $customer = $this->customer();
        $doc = $this->document();

        $this->linker()->attach($doc, $customer);
        $this->linker()->detach($doc, $customer);

        $this->assertSame(0, BusinessDocumentRelation::count());
        $this->assertNotNull($customer->fresh(), 'detaching deleted the customer');
        $this->assertNotNull($doc->fresh(), 'detaching deleted the document');
    }

    /**
     * The test that proves Documents is a layer and not a child table.
     *
     * A signed contract outlives the customer row it was filed against.
     * Cascading would destroy a business's own paperwork as a side effect of
     * tidying a contact list.
     */
    public function test_deleting_the_erp_record_leaves_the_document_standing(): void
    {
        $customer = $this->customer();
        $doc = $this->document(['kind' => 'contract', 'title' => 'Signed contract']);

        $this->linker()->attach($doc, $customer);

        $customer->delete();

        $this->assertNotNull($doc->fresh(), 'the contract died with the customer');
        $this->assertSame('Signed contract', $doc->fresh()->title);
    }

    /** Deleting the document does clear its links — those are Documents' own. */
    public function test_deleting_the_document_clears_its_links(): void
    {
        $customer = $this->customer();
        $doc = $this->document();

        $this->linker()->attach($doc, $customer);
        $doc->forceDelete();

        $this->assertSame(0, BusinessDocumentRelation::count());
        $this->assertNotNull($customer->fresh());
    }

    public function test_the_count_for_a_record_is_by_document_not_by_link(): void
    {
        $customer = $this->customer();
        $doc = $this->document();

        $this->linker()->attach($doc, $customer, 'about');
        $this->linker()->attach($doc, $customer, 'supporting');

        $this->assertSame(1, $this->linker()->countFor($customer), 'two roles, one document');
    }

    public function test_only_this_records_documents_are_returned(): void
    {
        $a = $this->customer('Client A');
        $b = $this->customer('Client B');

        $this->linker()->attach($this->document(['title' => 'A contract']), $a);
        $this->linker()->attach($this->document(['title' => 'B contract']), $b);

        $found = $this->linker()->documentsFor($a);

        $this->assertCount(1, $found);
        $this->assertSame('A contract', $found->first()->title);
    }

    /** The worst leak this module could produce. */
    public function test_a_document_cannot_be_linked_to_another_companys_record(): void
    {
        $otherOwner = User::factory()->create();
        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)), 'name' => 'Other Sarl',
            'owner_id' => $otherOwner->id, 'currency' => 'XAF',
            'plan' => 'business', 'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);
        $theirCustomer = Contact::create(['name' => 'Their Client', 'balance' => 0]);

        app(CurrentCompany::class)->set($this->company);
        $doc = $this->document();

        $this->expectException(RuntimeException::class);

        $this->linker()->attach($doc, $theirCustomer);
    }

    public function test_another_companys_relations_are_invisible(): void
    {
        $this->linker()->attach($this->document(), $this->customer());

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)), 'name' => 'Other Sarl',
            'owner_id' => User::factory()->create()->id, 'currency' => 'XAF',
            'plan' => 'business', 'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, BusinessDocumentRelation::count());
    }

    public function test_linking_without_a_company_is_refused(): void
    {
        $customer = $this->customer();
        $doc = $this->document();

        app(CurrentCompany::class)->set(null);

        $this->expectException(RuntimeException::class);

        $this->linker()->attach($doc, $customer);
    }

    /** A link whose target is gone must resolve to null, not explode. */
    public function test_a_relation_to_a_deleted_record_resolves_to_null(): void
    {
        $customer = $this->customer();
        $doc = $this->document();

        $this->linker()->attach($doc, $customer);
        $customer->forceDelete();

        $relation = $this->linker()->relationsOf($doc)->first();

        $this->assertNotNull($relation);
        $this->assertNull($relation->related);
    }

    public function test_role_labels_are_readable(): void
    {
        $relation = $this->linker()->attach($this->document(), $this->customer(), 'supporting');

        $this->assertSame('Supporting document', $relation->roleLabel());
    }
}
