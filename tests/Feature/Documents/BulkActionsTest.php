<?php

namespace Tests\Feature\Documents;

use App\Livewire\Papers\Index;
use App\Models\ActivityLog;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentFolder;
use App\Models\Role;
use App\Services\Documents\BulkActions;
use App\Services\Documents\DocumentFiler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ZipArchive;

/**
 * §2.16 — bulk operations on the papers workspace.
 *
 * The contract under test: bulk is the single-document path run N times with
 * per-item authorization, and a batch where one item is refused finishes the
 * others and says so by name — never a silent skip, never a whole-batch
 * failure.
 */
class BulkActionsTest extends DocumentsTestCase
{
    protected function folder(string $name = 'Legal'): BusinessDocumentFolder
    {
        return BusinessDocumentFolder::create([
            'name' => $name,
            'kind' => BusinessDocumentFolder::COMPANY,
        ]);
    }

    public function test_a_batch_moves_each_document_and_reports_it(): void
    {
        $this->actingAs($this->owner);

        $folder = $this->folder();
        $a = $this->document(['title' => 'First']);
        $b = $this->document(['title' => 'Second']);

        $report = app(BulkActions::class)->move(
            BusinessDocument::query()->whereIn('id', [$a->id, $b->id])->get(),
            $folder,
            $this->owner,
        );

        $this->assertCount(2, $report['done']);
        $this->assertCount(0, $report['refused']);
        $this->assertSame($folder->id, $a->fresh()->folder_id);
        $this->assertSame($folder->id, $b->fresh()->folder_id);
    }

    public function test_each_item_in_a_batch_gets_its_own_audit_row(): void
    {
        $this->actingAs($this->owner);

        $folder = $this->folder();
        $a = $this->document(['title' => 'First']);
        $b = $this->document(['title' => 'Second']);

        app(BulkActions::class)->move(
            BusinessDocument::query()->whereIn('id', [$a->id, $b->id])->get(),
            $folder,
            $this->owner,
        );

        foreach ([$a, $b] as $paper) {
            $this->assertSame(1, ActivityLog::query()
                ->where('subject_type', BusinessDocument::class)
                ->where('subject_id', $paper->id)
                ->where('event', 'updated')
                ->count(), $paper->title.' must have its own audit row');
        }
    }

    public function test_a_mixed_archive_batch_reports_per_item_outcomes(): void
    {
        $this->actingAs($this->owner);

        $issued = $this->document(['title' => 'Signed contract']);
        $issued->forceFill(['status' => 'issued', 'issued_at' => now()])->save();

        $draft = $this->document(['title' => 'Still a draft']);

        $held = $this->document(['title' => 'Contract under dispute']);
        $held->forceFill([
            'status' => 'issued', 'issued_at' => now(),
            'legal_hold' => true, 'legal_hold_reason' => 'Litigation',
        ])->save();

        $report = app(BulkActions::class)->archive(
            BusinessDocument::query()->whereIn('id', [$issued->id, $draft->id, $held->id])->get(),
            $this->owner,
        );

        $this->assertSame(['Signed contract'], $report['done'], json_encode($report));
        $this->assertCount(2, $report['refused']);

        $reasons = collect($report['refused'])->keyBy('title');
        $this->assertStringContainsString('legal hold', $reasons['Contract under dispute']['reason']);
        $this->assertStringContainsString('issued', $reasons['Still a draft']['reason']);

        // The held document survives the batch untouched.
        $held = $held->fresh();
        $this->assertSame('issued', $held->status);
        $this->assertTrue($held->isUnderLegalHold());

        // The draft survives too.
        $this->assertSame('draft', $draft->fresh()->status);

        $this->assertSame('void', $issued->fresh()->status);
    }

    public function test_a_user_without_papers_manage_cannot_bulk_file_a_restricted_paper(): void
    {
        $manager = $this->memberAt(Role::MANAGER);
        $this->actingAs($manager);

        $folder = $this->folder();
        $ordinary = $this->document(['title' => 'Ordinary letter', 'owner_id' => $manager->id]);
        $restricted = $this->document(['title' => 'Board minutes', 'security' => 'restricted', 'owner_id' => $this->owner->id]);

        $report = app(BulkActions::class)->move(
            BusinessDocument::query()->whereIn('id', [$ordinary->id, $restricted->id])->get(),
            $folder,
            $manager,
        );

        $this->assertSame(['Ordinary letter'], $report['done']);
        $this->assertCount(1, $report['refused']);
        $this->assertSame('Board minutes', $report['refused'][0]['title']);

        $this->assertSame($folder->id, $ordinary->fresh()->folder_id);
        $this->assertNull($restricted->fresh()->folder_id);
    }

    public function test_bulk_tagging_adds_without_clobbering_existing_tags(): void
    {
        $this->actingAs($this->owner);

        $a = $this->document(['title' => 'Tagged already', 'tags' => ['legal']]);
        $b = $this->document(['title' => 'Untagged']);

        $report = app(BulkActions::class)->tag(
            BusinessDocument::query()->whereIn('id', [$a->id, $b->id])->get(),
            '2026',
            $this->owner,
        );

        $this->assertCount(2, $report['done']);
        $this->assertSame(['legal', '2026'], $a->fresh()->tags);
        $this->assertSame(['2026'], $b->fresh()->tags);
    }

    public function test_bulk_untagging_removes_only_that_tag(): void
    {
        $this->actingAs($this->owner);

        $a = $this->document(['title' => 'Two tags', 'tags' => ['legal', '2026']]);

        app(BulkActions::class)->untag(
            BusinessDocument::query()->whereKey($a->id)->get(),
            '2026',
            $this->owner,
        );

        $this->assertSame(['legal'], $a->fresh()->tags);
    }

    public function test_bulk_classification_sets_security_per_item(): void
    {
        $this->actingAs($this->owner);

        $a = $this->document(['title' => 'To be marked']);

        $report = app(BulkActions::class)->classify(
            BusinessDocument::query()->whereKey($a->id)->get(),
            'confidential',
            $this->owner,
        );

        $this->assertCount(1, $report['done']);
        $this->assertSame('confidential', $a->fresh()->security);
    }

    public function test_bulk_download_zips_the_stored_files(): void
    {
        Storage::fake(DocumentFiler::DISK);
        $this->actingAs($this->owner);

        $filer = app(DocumentFiler::class);
        $uploaded = $filer->upload(UploadedFile::fake()->create('contract.pdf', 12, 'application/pdf'), $this->owner);
        $composed = $this->document(['title' => 'Composed, no file']);

        $path = app(BulkActions::class)->zip(
            BusinessDocument::query()->whereIn('id', [$uploaded->id, $composed->id])->get(),
            $this->owner,
        );

        $this->assertFileExists($path);

        $zip = new ZipArchive;
        $zip->open($path);
        $this->assertSame(1, $zip->numFiles);
        $zip->close();

        @unlink($path);
    }

    /** ZipArchive writes nothing at all for an empty archive; the service must still hand back a real file. */
    public function test_bulk_download_of_only_composed_documents_still_produces_a_file(): void
    {
        Storage::fake(DocumentFiler::DISK);
        $this->actingAs($this->owner);

        $composed = $this->document(['title' => 'Composed, no file']);

        $path = app(BulkActions::class)->zip(
            BusinessDocument::query()->whereKey($composed->id)->get(),
            $this->owner,
        );

        $this->assertFileExists($path);

        @unlink($path);
    }

    public function test_the_workspace_selects_moves_and_reports_in_one_summary(): void
    {
        $this->actingAs($this->owner);

        $folder = $this->folder();
        $a = $this->document(['title' => 'First']);
        $b = $this->document(['title' => 'Second']);

        $component = Livewire::test(Index::class)
            ->set('selected', [$a->id, $b->id])
            ->set('bulkFolderId', $folder->id)
            ->call('bulkMove')
            ->assertSet('selected', []);

        $this->assertSame($folder->id, $a->fresh()->folder_id);
        $this->assertSame($folder->id, $b->fresh()->folder_id);
        $this->assertStringContainsString('2 moved', $component->get('bulkSummary'));
    }

    public function test_the_workspace_summary_names_a_refused_document(): void
    {
        $this->actingAs($this->owner);

        $held = $this->document(['title' => 'Contract under dispute']);
        $held->forceFill(['status' => 'issued', 'issued_at' => now(), 'legal_hold' => true])->save();

        $ok = $this->document(['title' => 'Plain letter']);
        $ok->forceFill(['status' => 'issued', 'issued_at' => now()])->save();

        $component = Livewire::test(Index::class)
            ->set('selected', [$held->id, $ok->id])
            ->call('bulkArchive');

        $summary = $component->get('bulkSummary');
        $this->assertStringContainsString('1 archived', $summary);
        $this->assertStringContainsString('1 refused', $summary);
        $this->assertStringContainsString('Contract under dispute', $summary);
        $this->assertStringContainsString('legal hold', $summary);
    }

    public function test_select_page_and_clear_selection(): void
    {
        $this->actingAs($this->owner);

        $this->document(['title' => 'First']);
        $this->document(['title' => 'Second']);

        $component = Livewire::test(Index::class)->call('selectPage');
        $this->assertCount(2, $component->get('selected'));

        $component->call('clearSelection')->assertSet('selected', []);
    }
}
