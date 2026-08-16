<?php

namespace Tests\Feature\Documents;

use App\Models\Role;
use Laravel\Sanctum\Sanctum;

class VersionApiTest extends DocumentsTestCase
{
    public function test_versions_are_listed_in_order(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document(['title' => 'One']);
        $paper->update(['title' => 'Two']);

        $this->getJson('/api/v1/library/'.$paper->id.'/versions')
            ->assertOk()
            ->assertJsonPath('data.0.version', 1)
            ->assertJsonPath('data.1.version', 2);
    }

    public function test_comparing_two_versions(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document(['title' => 'Original']);
        $paper->update(['title' => 'Changed']);

        $this->getJson('/api/v1/library/'.$paper->id.'/versions/compare?from=1&to=2')
            ->assertOk()
            ->assertJsonPath('data.fields.title.changed', true);
    }

    public function test_a_missing_version_number_404s(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();

        $this->getJson('/api/v1/library/'.$paper->id.'/versions/compare?from=1&to=99')
            ->assertNotFound();
    }

    public function test_restoring_a_version_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document(['title' => 'Original']);
        $paper->update(['title' => 'Changed']);
        $original = $paper->fresh()->versions()->where('version_number', 1)->first();

        $this->postJson("/api/v1/library/{$paper->id}/versions/{$original->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.title', 'Original');
    }

    /**
     * The `update` ability itself already requires a draft, so this is
     * refused at authorization — before the service gets a chance to throw.
     * Same outcome the screen would hit, reached the same way.
     */
    public function test_an_issued_document_cannot_be_restored_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();
        $paper->issued_at = now();
        $paper->forceFill(['status' => 'issued', 'content_hash' => hash('sha256', $paper->canonicalPayload())])->save();
        $version = $paper->versions()->first();

        $this->postJson("/api/v1/library/{$paper->id}/versions/{$version->id}/restore")
            ->assertForbidden();
    }

    /** A version belonging to a different document cannot be used to restore this one. */
    public function test_restoring_with_a_version_from_another_document_is_refused(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();
        $other = $this->document();
        $foreignVersion = $other->versions()->first();

        $this->postJson("/api/v1/library/{$paper->id}/versions/{$foreignVersion->id}/restore")
            ->assertNotFound();
    }

    public function test_a_restricted_documents_versions_are_hidden_from_someone_who_cannot_see_it(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        Sanctum::actingAs($clerk, ['*']);
        $paper = $this->document(['security' => 'restricted']);

        $this->getJson('/api/v1/library/'.$paper->id.'/versions')->assertForbidden();
    }
}
