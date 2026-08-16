<?php

namespace Tests\Feature\Documents;

use App\Models\Role;
use Laravel\Sanctum\Sanctum;

class TemplateApiTest extends DocumentsTestCase
{
    public function test_a_template_can_be_created(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/document-templates', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'Board Resolution')
            ->assertJsonPath('data.is_published', false);
    }

    public function test_a_manager_cannot_create_a_template(): void
    {
        Sanctum::actingAs($this->memberAt(Role::MANAGER), ['*']);

        $this->postJson('/api/v1/document-templates', $this->payload())->assertForbidden();
    }

    public function test_a_manager_can_still_list_templates(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->postJson('/api/v1/document-templates', $this->payload());

        Sanctum::actingAs($this->memberAt(Role::MANAGER), ['*']);

        $this->getJson('/api/v1/document-templates')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_key_colliding_with_a_built_in_template_is_refused(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/document-templates', $this->payload(['key' => 'service_agreement']))
            ->assertStatus(422);
    }

    public function test_a_template_can_be_published(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $id = $this->postJson('/api/v1/document-templates', $this->payload())->json('data.id');

        $this->postJson("/api/v1/document-templates/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.is_published', true);
    }

    public function test_editing_returns_the_updated_fields(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $id = $this->postJson('/api/v1/document-templates', $this->payload())->json('data.id');

        $this->putJson("/api/v1/document-templates/{$id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_versions_are_listed(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $id = $this->postJson('/api/v1/document-templates', $this->payload())->json('data.id');
        $this->putJson("/api/v1/document-templates/{$id}", ['body' => 'Changed body.']);

        $this->getJson("/api/v1/document-templates/{$id}/versions")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_template_can_be_deleted(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $id = $this->postJson('/api/v1/document-templates', $this->payload())->json('data.id');

        $this->deleteJson("/api/v1/document-templates/{$id}")->assertNoContent();
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'custom_board_resolution',
            'name' => 'Board Resolution',
            'body' => 'Resolved that the matter is approved.',
        ], $overrides);
    }
}
