<?php

namespace Tests\Feature\Documents;

use App\Models\Role;
use Laravel\Sanctum\Sanctum;

class ActivityApiTest extends DocumentsTestCase
{
    public function test_the_timeline_includes_creation(): void
    {
        Sanctum::actingAs($this->owner, ['*']);
        $paper = $this->document();

        $this->getJson('/api/v1/library/'.$paper->id.'/activity')
            ->assertOk()
            ->assertJsonFragment(['type' => 'created']);
    }

    public function test_a_restricted_documents_activity_is_hidden(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        Sanctum::actingAs($clerk, ['*']);
        $paper = $this->document(['security' => 'restricted']);

        $this->getJson('/api/v1/library/'.$paper->id.'/activity')->assertForbidden();
    }
}
