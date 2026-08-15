<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocument;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class DocumentsTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->owner = User::factory()->create();
        $this->company = Company::create([
            'slug' => 'acme-'.Str::lower(Str::random(6)),
            'name' => 'Acme Sarl',
            'owner_id' => $this->owner->id,
            'currency' => 'XAF',
            'plan' => 'business',
            'account_type' => 'active',
        ]);

        $this->joinCompany($this->company, $this->owner, Role::OWNER);
        $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
        app(CurrentCompany::class)->set($this->company);
    }

    protected function document(array $attributes = []): BusinessDocument
    {
        return BusinessDocument::create(array_merge([
            'template' => 'service_agreement',
            'title' => 'Service agreement',
            'reference' => 'DOC-'.Str::upper(Str::random(5)),
            'recipient' => 'Un Client',
            'fields' => ['client_name' => 'Un Client'],
            'body' => 'The agreed terms.',
            'status' => 'draft',
            'created_by' => $this->owner->id,
        ], $attributes));
    }
}
