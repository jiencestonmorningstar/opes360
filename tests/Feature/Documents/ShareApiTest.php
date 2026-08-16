<?php

namespace Tests\Feature\Documents;

use App\Models\Role;
use App\Services\Documents\DocumentSharing;
use Laravel\Sanctum\Sanctum;

class ShareApiTest extends DocumentsTestCase
{
    public function test_a_share_link_can_be_created_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();

        $this->postJson("/api/v1/library/{$paper->id}/shares", [])
            ->assertCreated()
            ->assertJsonPath('data.password_protected', false)
            ->assertJsonPath('data.is_live', true);
    }

    public function test_the_response_never_carries_the_password(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();

        $response = $this->postJson("/api/v1/library/{$paper->id}/shares", ['password' => 'letmein']);

        $response->assertJsonMissingPath('data.password');
        $response->assertJsonMissingPath('data.password_hash');
    }

    public function test_a_cashier_cannot_create_a_share_link(): void
    {
        Sanctum::actingAs($this->memberAt(Role::CASHIER), ['*']);

        $this->postJson('/api/v1/library/'.$this->document()->id.'/shares', [])->assertForbidden();
    }

    public function test_shares_are_listed(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();
        app(DocumentSharing::class)->create($paper, $this->owner);

        $this->getJson("/api/v1/library/{$paper->id}/shares")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_share_can_be_revoked_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();
        $share = app(DocumentSharing::class)->create($paper, $this->owner);

        $this->postJson("/api/v1/library/shares/{$share->id}/revoke")
            ->assertOk()
            ->assertJsonPath('data.is_live', false);
    }

    public function test_an_expiry_in_the_past_is_refused(): void
    {
        Sanctum::actingAs($this->owner, ['*']);

        $this->postJson('/api/v1/library/'.$this->document()->id.'/shares', [
            'expires_at' => now()->subDay()->toIso8601String(),
        ])->assertStatus(422);
    }
}
