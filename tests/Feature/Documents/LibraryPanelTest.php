<?php

namespace Tests\Feature\Documents;

use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Services\Documents\DocumentLinker;

class LibraryPanelTest extends DocumentsTestCase
{
    protected function customer(): Contact
    {
        return Contact::create(['name' => 'Un Client', 'balance' => 0]);
    }

    public function test_the_customer_profile_shows_the_library(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->owner)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Library')
            ->assertSee('No documents filed against this record yet.');
    }

    public function test_a_linked_document_appears_on_the_record(): void
    {
        $customer = $this->customer();
        $doc = $this->document(['title' => 'Supply agreement', 'kind' => 'contract']);

        app(DocumentLinker::class)->attach($doc, $customer, 'about', $this->owner);

        $this->actingAs($this->owner)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Supply agreement')
            ->assertSee('Contract');
    }

    /** The panel must not show documents belonging to a different record. */
    public function test_another_records_documents_do_not_appear(): void
    {
        $a = $this->customer();
        $b = Contact::create(['name' => 'Autre Client', 'balance' => 0]);

        app(DocumentLinker::class)->attach($this->document(['title' => 'B agreement']), $b);

        $this->actingAs($this->owner)
            ->get(route('customers.show', $a))
            ->assertOk()
            ->assertDontSee('B agreement');
    }

    public function test_a_confidential_document_is_marked_as_such(): void
    {
        $customer = $this->customer();

        app(DocumentLinker::class)->attach(
            $this->document(['title' => 'Settlement', 'security' => 'confidential']),
            $customer,
        );

        $this->actingAs($this->owner)
            ->get(route('customers.show', $customer))
            ->assertOk()
            ->assertSee('Confidential');
    }

    /**
     * The panel is gated on papers.view, not on the customer screen's own
     * permission. Somebody who may see a customer is not automatically
     * entitled to that customer's contracts.
     */
    public function test_a_user_without_papers_view_sees_no_library(): void
    {
        $customer = $this->customer();

        app(DocumentLinker::class)->attach($this->document(['title' => 'Supply agreement']), $customer);

        $cashier = User::factory()->create();
        $this->joinCompany($this->company, $cashier, Role::CASHIER);
        $cashier->forceFill(['current_company_id' => $this->company->id])->save();

        $response = $this->actingAs($cashier)->get(route('customers.show', $customer));

        if ($response->status() === 200) {
            $response->assertDontSee('Supply agreement');
        } else {
            $this->assertTrue(in_array($response->status(), [403, 302], true));
        }
    }

    /**
     * The two panels are named differently on purpose: a customer profile
     * already has a Documents panel listing invoices, and two panels with the
     * same name would leave somebody guessing which holds the contract.
     */
    public function test_the_library_panel_is_not_called_documents(): void
    {
        $markup = (string) file_get_contents(
            resource_path('views/components/documents/library-panel.blade.php')
        );

        $this->assertStringContainsString("'title' => 'Library'", $markup);
    }
}
