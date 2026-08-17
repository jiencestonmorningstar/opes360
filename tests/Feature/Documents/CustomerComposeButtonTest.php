<?php

namespace Tests\Feature\Documents;

use App\Livewire\Customers\Show;
use App\Models\Contact;
use App\Models\Role;
use App\Models\User;
use App\Services\Documents\CustomDocumentTemplates;
use App\Services\Documents\DocumentLinker;
use Livewire\Livewire;

/**
 * The one entry point the creation-routes work landed a service for but no
 * button: "New document" on a customer's own page. Proves the button reaches
 * RecordDocumentComposer, not that the composer itself works — that is
 * DocumentCreationRoutesTest's job.
 */
class CustomerComposeButtonTest extends DocumentsTestCase
{
    public function test_composing_from_the_customer_screen_creates_a_linked_draft_and_opens_it(): void
    {
        $this->publishGreetingTemplate();
        $contact = Contact::create(['name' => 'Aïcha Njoya', 'email' => 'aicha@example.com', 'balance' => 0]);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['contact' => $contact])
            ->set('composing', true)
            ->set('composeTemplate', 'greeting')
            ->call('composeForCustomer')
            ->assertHasNoErrors()
            ->assertRedirect();

        $document = app(DocumentLinker::class)->documentsFor($contact)->sole();

        $this->assertTrue($document->isDraft());
        $this->assertStringContainsString('Aïcha Njoya', $document->body);
        $this->assertStringContainsString('Aïcha Njoya', $document->title);
    }

    public function test_an_unknown_template_is_refused_without_creating_anything(): void
    {
        $contact = Contact::create(['name' => 'Paul Biya Jr', 'balance' => 0]);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['contact' => $contact])
            ->set('composing', true)
            ->set('composeTemplate', 'not-a-real-template')
            ->call('composeForCustomer')
            ->assertHasErrors('composeTemplate');

        $this->assertCount(0, app(DocumentLinker::class)->documentsFor($contact));
    }

    public function test_somebody_without_papers_create_cannot_compose(): void
    {
        $contact = Contact::create(['name' => 'Someone Else', 'balance' => 0]);
        $this->publishGreetingTemplate();

        $outsider = User::factory()->create();
        $this->joinCompany($this->company, $outsider, Role::CASHIER);

        Livewire::actingAs($outsider)
            ->test(Show::class, ['contact' => $contact])
            ->set('composing', true)
            ->set('composeTemplate', 'greeting')
            ->call('composeForCustomer')
            ->assertForbidden();
    }

    protected function publishGreetingTemplate(): void
    {
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'greeting',
            'name' => 'Greeting',
            'body' => 'Dear {{ customer.name }},',
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);
    }
}
