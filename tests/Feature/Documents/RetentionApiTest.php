<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentRetentionPolicy;
use App\Models\Role;
use Laravel\Sanctum\Sanctum;

class RetentionApiTest extends DocumentsTestCase
{
    public function test_retention_status_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        BusinessDocumentRetentionPolicy::create(['kind' => null, 'retain_years' => 5, 'created_by' => $this->owner->id]);

        $this->getJson('/api/v1/library/'.$this->document()->id.'/retention')
            ->assertOk()
            ->assertJsonPath('data.legal_hold', false)
            ->assertJsonPath('data.is_disposable', false);
    }

    public function test_placing_a_legal_hold_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();

        $this->postJson("/api/v1/library/{$paper->id}/legal-hold", ['reason' => 'Litigation.'])
            ->assertOk()
            ->assertJsonPath('data.legal_hold', true);

        $this->assertTrue($paper->fresh()->isUnderLegalHold());
    }

    public function test_a_manager_cannot_place_a_legal_hold(): void
    {
        Sanctum::actingAs($this->memberAt(Role::MANAGER), ['*']);
        $paper = $this->document();

        $this->postJson("/api/v1/library/{$paper->id}/legal-hold", ['reason' => 'Litigation.'])
            ->assertForbidden();
    }

    public function test_lifting_a_legal_hold_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();
        $this->postJson("/api/v1/library/{$paper->id}/legal-hold", ['reason' => 'Litigation.']);

        $this->deleteJson("/api/v1/library/{$paper->id}/legal-hold")
            ->assertOk()
            ->assertJsonPath('data.legal_hold', false);

        $this->assertFalse($paper->fresh()->isUnderLegalHold());
    }

    public function test_disposing_before_retention_is_refused(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        BusinessDocumentRetentionPolicy::create(['kind' => null, 'retain_years' => 5, 'created_by' => $this->owner->id]);
        $paper = $this->document();

        $this->deleteJson("/api/v1/library/{$paper->id}/dispose")->assertStatus(422);

        $this->assertDatabaseHas('business_documents', ['id' => $paper->id]);
    }

    public function test_disposing_after_retention_removes_the_document(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        BusinessDocumentRetentionPolicy::create(['kind' => null, 'retain_years' => 1, 'created_by' => $this->owner->id]);
        $paper = $this->document();
        $this->travel(2)->years();

        $this->deleteJson("/api/v1/library/{$paper->id}/dispose")->assertNoContent();

        $this->assertDatabaseCount('business_documents', 0);
    }
}
