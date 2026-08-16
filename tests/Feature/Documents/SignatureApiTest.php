<?php

namespace Tests\Feature\Documents;

use App\Models\Role;
use Laravel\Sanctum\Sanctum;

class SignatureApiTest extends DocumentsTestCase
{
    public function test_requesting_signatures_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();

        $this->postJson("/api/v1/library/{$paper->id}/signatures", [
            'signers' => [['name' => 'A Client', 'email' => 'client@example.com']],
        ])->assertCreated()->assertJsonCount(1, 'data');
    }

    public function test_a_cashier_cannot_request_signatures(): void
    {
        Sanctum::actingAs($this->memberAt(Role::CASHIER), ['*']);
        $paper = $this->document();

        $this->postJson("/api/v1/library/{$paper->id}/signatures", [
            'signers' => [['name' => 'A Client', 'email' => 'client@example.com']],
        ])->assertForbidden();
    }

    public function test_signature_status_over_the_api(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();
        $this->postJson("/api/v1/library/{$paper->id}/signatures", [
            'signers' => [['name' => 'A Client', 'email' => 'client@example.com']],
        ]);

        $this->getJson("/api/v1/library/{$paper->id}/signatures")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.total', 1);
    }
}
