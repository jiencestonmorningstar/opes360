<?php

namespace Tests\Feature\Api;

use App\Support\Modules;

/**
 * The service desk over the token API.
 *
 * The assertions worth making are the desk's own rules holding over HTTP:
 * everything goes through TicketDesk, the first response is the one that
 * counts, and a settled ticket refuses further work with a sentence.
 */
class ServiceDeskApiTest extends Wave4ApiTestCase
{
    public function test_a_ticket_can_be_opened_listed_and_read(): void
    {
        $contact = $this->makeContact();

        $id = $this->postJson('/api/v1/service/tickets', [
            'subject' => 'Freezer not cooling',
            'description' => 'Compressor cycles but no cold.',
            'contact_id' => $contact->id,
            'priority' => 'high',
            'channel' => 'phone',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'new')
            ->assertJsonPath('data.priority', 'high')
            ->json('data.id');

        $this->assertNotNull($this->getJson('/api/v1/service/tickets')->assertOk()->json('data.0.reference'));

        // The show route carries the event history — opening wrote its row.
        $this->getJson("/api/v1/service/tickets/{$id}")
            ->assertOk()
            ->assertJsonPath('data.events.0.kind', 'opened');
    }

    public function test_only_the_first_response_moves_the_clock(): void
    {
        $id = $this->postJson('/api/v1/service/tickets', ['subject' => 'Printer jam'])->json('data.id');

        $first = $this->postJson("/api/v1/service/tickets/{$id}/respond")
            ->assertOk()->json('data.first_response_at');

        $this->assertNotNull($first);

        // A second answer changes nothing: the promise was about the first.
        $this->travel(10)->minutes();

        $this->postJson("/api/v1/service/tickets/{$id}/respond")
            ->assertOk()->assertJsonPath('data.first_response_at', $first);
    }

    public function test_a_resolved_ticket_refuses_to_be_resolved_again(): void
    {
        $id = $this->postJson('/api/v1/service/tickets', ['subject' => 'Broken door'])->json('data.id');

        $this->postJson("/api/v1/service/tickets/{$id}/resolve", ['resolution' => 'Hinge replaced.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution', 'Hinge replaced.');

        $this->postJson("/api/v1/service/tickets/{$id}/resolve")
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_the_module_switch_closes_the_whole_surface(): void
    {
        $this->company->forceFill(['modules' => ['service' => false]])->save();
        Modules::flush();

        $this->getJson('/api/v1/service/tickets')->assertForbidden();
        $this->postJson('/api/v1/service/tickets', ['subject' => 'Nope'])->assertForbidden();
    }
}
