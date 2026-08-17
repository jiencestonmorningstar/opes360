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
}
