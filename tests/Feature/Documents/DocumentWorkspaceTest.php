<?php

namespace Tests\Feature\Documents;

use App\Livewire\Papers\Index;
use App\Models\BusinessDocumentFolder;
use App\Models\Role;
use Livewire\Livewire;

/**
 * Phase 2.1 of the Documents plan: the Papers index grows from a plain list
 * into the workspace — search, filters, folder tree, overview counters.
 *
 * The gallery and the six existing Papers regression tests
 * (DocumentGeneratorTest, FillPreviewTest, LetterheadDesignsTest,
 * NewBusinessDocumentTemplatesTest, PrintFidelityTest, DocumentImmutabilityTest)
 * must keep passing untouched — this only grows the screen, it does not
 * change how a document is made.
 */
class DocumentWorkspaceTest extends DocumentsTestCase
{
    public function test_the_overview_counters_are_correct(): void
    {
        $this->actingAs($this->owner);

        $this->document(['title' => 'Draft one']);
        $this->document(['title' => 'Draft two', 'owner_id' => $this->owner->id]);
        $this->document(['title' => 'Expiring soon', 'expires_on' => now()->addDays(5)->toDateString()]);
        $archived = $this->document(['title' => 'Old one']);
        $archived->forceFill(['status' => 'issued', 'issued_at' => now()->subYear()])->save();
        $archived->update(['status' => 'void']);

        $counters = Livewire::test(Index::class)->viewData('counters');

        $this->assertSame(4, $counters['total']);
        $this->assertSame(1, $counters['mine']);
        $this->assertSame(3, $counters['drafts']);
        $this->assertSame(1, $counters['expiring']);
    }

    public function test_filtering_by_kind(): void
    {
        $this->actingAs($this->owner);

        $this->document(['title' => 'A contract', 'kind' => 'contract']);
        $this->document(['title' => 'A memo', 'kind' => 'memo']);

        Livewire::test(Index::class)
            ->set('kind', 'contract')
            ->assertSee('A contract')
            ->assertDontSee('A memo');
    }

    public function test_filtering_by_security_level(): void
    {
        $this->actingAs($this->owner);

        $this->document(['title' => 'Public one', 'security' => 'internal']);
        $this->document(['title' => 'Guarded one', 'security' => 'confidential']);

        Livewire::test(Index::class)
            ->set('security', 'confidential')
            ->assertSee('Guarded one')
            ->assertDontSee('Public one');
    }

    public function test_filtering_by_folder(): void
    {
        $this->actingAs($this->owner);

        $folder = BusinessDocumentFolder::create(['name' => 'Contracts', 'kind' => 'company']);
        $this->document(['title' => 'Filed', 'folder_id' => $folder->id]);
        $this->document(['title' => 'Unfiled']);

        Livewire::test(Index::class)
            ->set('folderId', $folder->id)
            ->assertSee('Filed')
            ->assertDontSee('Unfiled');
    }

    public function test_filtering_by_tag(): void
    {
        $this->actingAs($this->owner);

        $this->document(['title' => 'Tagged', 'tags' => ['urgent', '2026']]);
        $this->document(['title' => 'Untagged']);

        Livewire::test(Index::class)
            ->set('tag', 'urgent')
            ->assertSee('Tagged')
            ->assertDontSee('Untagged');
    }

    public function test_a_restricted_document_never_appears_to_somebody_who_should_not_see_it(): void
    {
        $clerk = $this->memberAt(Role::SALES_OFFICER);
        $this->actingAs($clerk);

        $this->document(['title' => 'Off limits', 'security' => 'restricted']);
        $this->document(['title' => 'Visible']);

        Livewire::test(Index::class)
            ->assertDontSee('Off limits')
            ->assertSee('Visible');
    }

    public function test_the_list_is_paginated_rather_than_unbounded(): void
    {
        $this->actingAs($this->owner);

        foreach (range(1, 15) as $n) {
            $this->document(['title' => "Doc {$n}"]);
        }

        $component = Livewire::test(Index::class);

        $this->assertLessThan(15, $component->viewData('papers')->count());
        $this->assertTrue($component->viewData('papers')->hasPages());
    }

    public function test_the_folder_tree_is_available_to_the_view(): void
    {
        $this->actingAs($this->owner);

        $parent = BusinessDocumentFolder::create(['name' => 'Sales', 'kind' => 'company']);
        BusinessDocumentFolder::create(['name' => 'Contracts', 'kind' => 'company', 'parent_id' => $parent->id]);

        $folders = Livewire::test(Index::class)->viewData('folders');

        $this->assertGreaterThanOrEqual(1, $folders->count());
    }
}
