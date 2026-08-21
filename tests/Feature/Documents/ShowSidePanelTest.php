<?php

namespace Tests\Feature\Documents;

use App\Livewire\Papers\Show;
use App\Models\Role;
use App\Services\Documents\DocumentComments;
use Livewire\Livewire;

/**
 * §53 audit: the document view's side panel (versions, comments, shares,
 * signatures, legal hold, activity) was entirely absent from Show.php even
 * though every one of those already existed as a service or relation for
 * the API layer. These tests pin the wiring added to close that gap.
 */
class ShowSidePanelTest extends DocumentsTestCase
{
    public function test_the_details_panel_reports_version_and_comment_counts(): void
    {
        $paper = $this->document();
        $expectedVersions = $paper->versions()->count();
        app(DocumentComments::class)->post($paper, $this->owner, 'Looks good.');

        $this->actingAs($this->owner)
            ->get(route('papers.show', $paper))
            ->assertOk()
            ->assertSee('Versions')
            ->assertSee('Comments');

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['paper' => $paper])
            ->assertViewHas('versionCount', $expectedVersions)
            ->assertViewHas('commentCount', 1);
    }

    public function test_a_legal_hold_shows_on_the_panel(): void
    {
        $paper = $this->document();
        $paper->forceFill([
            'legal_hold' => true,
            'legal_hold_reason' => 'Litigation.',
            'legal_hold_set_by' => $this->owner->id,
            'legal_hold_set_at' => now(),
        ])->save();

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['paper' => $paper->fresh()])
            ->assertSee('Legal hold')
            ->assertSee('On hold');
    }

    public function test_activity_is_only_shown_to_someone_who_may_manage_papers(): void
    {
        $paper = $this->document();

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['paper' => $paper])
            ->assertViewHas('activity', fn ($activity) => $activity->isNotEmpty());

        $cashier = $this->memberAt(Role::CASHIER);

        Livewire::actingAs($cashier)
            ->test(Show::class, ['paper' => $paper])
            ->assertViewHas('activity', fn ($activity) => $activity->isEmpty());
    }

    // ── Requesting signatures — the write UI §53 explicitly left out ─────

    public function test_requesting_signatures_creates_the_round_and_closes_the_form(): void
    {
        $paper = $this->document();

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['paper' => $paper])
            ->call('openSignatureForm')
            ->set('signers.0.name', 'Ada Lovelace')
            ->set('signers.0.email', 'ada@example.com')
            ->call('requestSignatures')
            ->assertSet('signatureFormOpen', false);

        $this->assertSame(1, $paper->fresh()->signatures()->count());
        $this->assertSame('Ada Lovelace', $paper->fresh()->signatures()->first()->signer_name);
    }

    public function test_a_second_signer_row_can_be_added_and_removed(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Show::class, ['paper' => $this->document()])
            ->call('openSignatureForm')
            ->call('addSigner')
            ->assertCount('signers', 2)
            ->call('removeSigner', 0)
            ->assertCount('signers', 1);
    }

    public function test_every_signer_needs_a_name_and_a_valid_email(): void
    {
        Livewire::actingAs($this->owner)
            ->test(Show::class, ['paper' => $this->document()])
            ->call('openSignatureForm')
            ->set('signers.0.name', '')
            ->set('signers.0.email', 'not-an-email')
            ->call('requestSignatures')
            ->assertHasErrors(['signers.0.name', 'signers.0.email']);
    }

    public function test_a_second_round_cannot_be_requested_while_one_is_pending(): void
    {
        $paper = $this->document();
        app(\App\Services\Documents\DocumentSignatureRequests::class)
            ->request($paper, [['name' => 'A', 'email' => 'a@example.com']]);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['paper' => $paper->fresh()])
            ->call('openSignatureForm')
            ->set('signers.0.name', 'B')
            ->set('signers.0.email', 'b@example.com')
            ->call('requestSignatures');

        // Refused by the service, not silently accepted as a second round.
        $this->assertSame(1, $paper->fresh()->signatures()->count());
    }

    public function test_someone_who_may_not_share_the_document_cannot_request_signatures(): void
    {
        $cashier = $this->memberAt(Role::CASHIER);
        $paper = $this->document();

        Livewire::actingAs($cashier)
            ->test(Show::class, ['paper' => $paper])
            ->call('openSignatureForm')
            ->assertStatus(403);
    }

    public function test_an_existing_rounds_status_is_listed_per_signer(): void
    {
        $paper = $this->document();
        app(\App\Services\Documents\DocumentSignatureRequests::class)
            ->request($paper, [['name' => 'Ada Lovelace', 'email' => 'ada@example.com']]);

        Livewire::actingAs($this->owner)
            ->test(Show::class, ['paper' => $paper->fresh()])
            ->assertSee('Ada Lovelace')
            ->assertSee('Pending');
    }
}
