<?php

namespace Tests\Feature\Documents;

use App\Models\Role;
use Laravel\Sanctum\Sanctum;

class NumberingSchemeApiTest extends DocumentsTestCase
{
    public function test_a_scheme_can_be_created(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/library/numbering-schemes', ['kind' => 'contract', 'prefix' => 'CONTRACT'])
            ->assertCreated()
            ->assertJsonPath('data.prefix', 'CONTRACT')
            ->assertJsonPath('data.kind', 'contract');
    }

    public function test_a_manager_cannot_create_a_scheme(): void
    {
        Sanctum::actingAs($this->memberAt(Role::MANAGER), ['*']);

        $this->postJson('/api/v1/library/numbering-schemes', ['kind' => 'contract', 'prefix' => 'CONTRACT'])
            ->assertForbidden();
    }

    public function test_a_manager_can_still_list_schemes(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $this->postJson('/api/v1/library/numbering-schemes', ['prefix' => 'BIZ']);

        Sanctum::actingAs($this->memberAt(Role::MANAGER), ['*']);

        $this->getJson('/api/v1/library/numbering-schemes')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_scheme_can_be_removed(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $response = $this->postJson('/api/v1/library/numbering-schemes', ['prefix' => 'BIZ']);
        $id = $response->json('data.id');

        $this->deleteJson("/api/v1/library/numbering-schemes/{$id}")->assertNoContent();

        $this->assertDatabaseCount('business_document_numbering_schemes', 0);
    }

    public function test_saving_a_scheme_twice_for_the_same_kind_updates_it_rather_than_duplicating(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/library/numbering-schemes', ['kind' => 'contract', 'prefix' => 'A']);
        $this->postJson('/api/v1/library/numbering-schemes', ['kind' => 'contract', 'prefix' => 'B']);

        $this->assertDatabaseCount('business_document_numbering_schemes', 1);
        $this->assertDatabaseHas('business_document_numbering_schemes', ['kind' => 'contract', 'prefix' => 'B']);
    }

    public function test_a_lowercase_prefix_is_refused(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/library/numbering-schemes', ['prefix' => 'contract'])
            ->assertStatus(422);
    }
}
