<?php

namespace Tests\Feature\Documents;

use App\Models\Contact;
use App\Models\Employee;
use App\Models\Project;
use App\Services\DocumentComposer;
use App\Services\Documents\CustomDocumentTemplates;
use App\Services\Documents\DocumentFieldRegistry;

class DocumentFieldRegistryTest extends DocumentsTestCase
{
    public function test_the_default_company_provider_is_already_registered(): void
    {
        $values = $this->registry()->all($this->company);

        $this->assertSame($this->company->name, $values['company.name']);
        $this->assertArrayHasKey('today', $values);
    }

    /** A module can add a field the registry knew nothing about a moment ago. */
    public function test_a_new_provider_can_be_registered_at_runtime(): void
    {
        $this->registry()->register('widget', fn () => ['widget.count' => 42]);

        $this->assertSame(42, $this->registry()->all($this->company)['widget.count']);
    }

    public function test_a_provider_with_nothing_to_contribute_returns_nothing(): void
    {
        $values = $this->registry()->all($this->company);

        $this->assertArrayNotHasKey('customer.name', $values);
    }

    public function test_the_customer_provider_fires_once_a_contact_is_in_context(): void
    {
        $contact = Contact::create(['name' => 'A Customer', 'email' => 'a@example.com', 'balance' => 0]);

        $values = $this->registry()->all($this->company, ['customer' => $contact]);

        $this->assertSame('A Customer', $values['customer.name']);
        $this->assertSame('a@example.com', $values['customer.email']);
    }

    public function test_the_employee_provider_fires_once_an_employee_is_in_context(): void
    {
        $employee = Employee::create([
            'first_name' => 'Aïcha', 'last_name' => 'Njoya', 'status' => 'active',
            'job_title' => 'Accountant',
        ]);

        $values = $this->registry()->all($this->company, ['employee' => $employee]);

        $this->assertSame('Aïcha Njoya', $values['employee.name']);
        $this->assertSame('Accountant', $values['employee.job_title']);
    }

    public function test_the_project_provider_fires_once_a_project_is_in_context(): void
    {
        $project = Project::create(['name' => 'Website rebuild', 'code' => 'WEB', 'status' => 'active', 'created_by' => $this->owner->id]);

        $values = $this->registry()->all($this->company, ['project' => $project]);

        $this->assertSame('Website rebuild', $values['project.name']);
        $this->assertSame('WEB', $values['project.code']);
    }

    /** A field bound to a customer works end to end through merge(), not just the registry directly. */
    public function test_a_custom_template_can_use_a_customer_field_through_compose(): void
    {
        $contact = Contact::create(['name' => 'A Customer', 'balance' => 0]);
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'greeting',
            'name' => 'Greeting',
            'body' => 'Dear {{ customer.name }},',
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);

        $merged = app(DocumentComposer::class)->merge('greeting', [], $this->company, ['customer' => $contact]);

        $this->assertStringContainsString('Dear A Customer,', $merged);
    }

    /** Composing with no context at all still works — every provider contributes nothing gracefully. */
    public function test_composing_with_no_context_leaves_erp_placeholders_blank_rather_than_erroring(): void
    {
        $template = app(CustomDocumentTemplates::class)->create([
            'key' => 'blank_greeting',
            'name' => 'Blank Greeting',
            'body' => 'Dear {{ customer.name }},',
        ], $this->owner);
        app(CustomDocumentTemplates::class)->publish($template);

        $merged = app(DocumentComposer::class)->merge('blank_greeting', [], $this->company);

        $this->assertStringContainsString('Dear ,', $merged);
    }

    protected function registry(): DocumentFieldRegistry
    {
        return app(DocumentFieldRegistry::class);
    }
}
