<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentFolder;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentFolderTest extends DocumentsTestCase
{
    protected function folder(string $name, ?BusinessDocumentFolder $parent = null, array $extra = []): BusinessDocumentFolder
    {
        return BusinessDocumentFolder::create(array_merge([
            'name' => $name,
            'parent_id' => $parent?->id,
            'kind' => BusinessDocumentFolder::COMPANY,
        ], $extra));
    }

    public function test_folders_nest(): void
    {
        $sales = $this->folder('Sales');
        $contracts = $this->folder('Contracts', $sales);

        $this->assertSame($sales->id, $contracts->parent->id);
        $this->assertCount(1, $sales->fresh()->children);
    }

    public function test_a_path_reads_from_the_root_down(): void
    {
        $year = $this->folder('2026', $this->folder('Contracts', $this->folder('Sales')));

        $this->assertSame('Sales / Contracts / 2026', $year->path());
    }

    public function test_depth_counts_from_one(): void
    {
        $sales = $this->folder('Sales');
        $contracts = $this->folder('Contracts', $sales);

        $this->assertSame(1, $sales->depth());
        $this->assertSame(2, $contracts->depth());
    }

    /**
     * The important half of the folder story. A folder is an arrangement, not
     * a container — losing a signed contract because somebody tidied the tree
     * is unrecoverable from the interface.
     */
    public function test_deleting_a_folder_does_not_delete_its_documents(): void
    {
        $folder = $this->folder('Contracts');
        $doc = $this->document(['title' => 'Signed contract']);
        $doc->forceFill(['folder_id' => $folder->id])->save();

        $folder->forceDelete();

        $fresh = $doc->fresh();

        $this->assertNotNull($fresh, 'the document died with its folder');
        $this->assertNull($fresh->folder_id, 'it should fall back to the root');
        $this->assertSame('Signed contract', $fresh->title);
    }

    /** Deleting a parent promotes its children rather than destroying a branch. */
    public function test_deleting_a_parent_does_not_destroy_its_subfolders(): void
    {
        $sales = $this->folder('Sales');
        $contracts = $this->folder('Contracts', $sales);

        $sales->forceDelete();

        $this->assertNotNull($contracts->fresh());
        $this->assertNull($contracts->fresh()->parent_id);
    }

    // ── Moves that would break the tree ──────────────────────────────────

    public function test_a_folder_cannot_be_moved_inside_itself(): void
    {
        $sales = $this->folder('Sales');

        $this->expectException(RuntimeException::class);

        $sales->moveTo($sales);
    }

    /**
     * The move that silently orphans a branch: the rows survive but nothing
     * can reach them.
     */
    public function test_a_folder_cannot_be_moved_inside_its_own_subfolder(): void
    {
        $sales = $this->folder('Sales');
        $contracts = $this->folder('Contracts', $sales);

        $this->expectException(RuntimeException::class);

        $sales->moveTo($contracts);
    }

    public function test_a_legitimate_move_is_allowed(): void
    {
        $sales = $this->folder('Sales');
        $legal = $this->folder('Legal');
        $contracts = $this->folder('Contracts', $sales);

        $contracts->moveTo($legal);

        $this->assertSame($legal->id, $contracts->fresh()->parent_id);
        $this->assertSame('Legal / Contracts', $contracts->fresh()->path());
    }

    public function test_a_folder_can_be_moved_to_the_root(): void
    {
        $contracts = $this->folder('Contracts', $this->folder('Sales'));

        $contracts->moveTo(null);

        $this->assertNull($contracts->fresh()->parent_id);
        $this->assertSame(1, $contracts->fresh()->depth());
    }

    /** Nobody navigates a six-deep tree; past that people lose documents in it. */
    public function test_nesting_is_capped(): void
    {
        $node = $this->folder('L1');

        for ($i = 2; $i <= BusinessDocumentFolder::MAX_DEPTH; $i++) {
            $node = $this->folder("L{$i}", $node);
        }

        $this->assertSame(BusinessDocumentFolder::MAX_DEPTH, $node->depth());

        $orphan = $this->folder('Too deep');

        $this->expectException(RuntimeException::class);

        $orphan->moveTo($node);
    }

    // ── Personal folders ─────────────────────────────────────────────────

    public function test_a_personal_folder_is_hidden_from_other_users(): void
    {
        $other = User::factory()->create();

        $this->folder('Shared');
        $this->folder('My drafts', null, [
            'kind' => BusinessDocumentFolder::PERSONAL,
            'owner_id' => $this->owner->id,
        ]);

        $this->assertCount(2, BusinessDocumentFolder::visibleTo($this->owner)->get());
        $this->assertCount(1, BusinessDocumentFolder::visibleTo($other)->get());
    }

    public function test_roots_and_shared_scopes(): void
    {
        $sales = $this->folder('Sales');
        $this->folder('Contracts', $sales);
        $this->folder('Mine', null, ['kind' => BusinessDocumentFolder::PERSONAL, 'owner_id' => $this->owner->id]);

        $this->assertCount(2, BusinessDocumentFolder::roots()->get());
        $this->assertCount(2, BusinessDocumentFolder::shared()->get());
    }

    // ── Filing ───────────────────────────────────────────────────────────

    public function test_a_document_can_be_filed_and_refiled(): void
    {
        $a = $this->folder('Sales');
        $b = $this->folder('Legal');
        $doc = $this->document();

        $doc->forceFill(['folder_id' => $a->id])->save();
        $this->assertCount(1, $a->fresh()->documents);

        $doc->forceFill(['folder_id' => $b->id])->save();
        $this->assertCount(0, $a->fresh()->documents);
        $this->assertCount(1, $b->fresh()->documents);
    }

    /** Filing is not editing, so an issued document can still be moved. */
    public function test_an_issued_document_can_be_filed(): void
    {
        $folder = $this->folder('Contracts');
        $doc = $this->document(['status' => 'issued', 'issued_at' => now()]);

        $doc->forceFill(['folder_id' => $folder->id])->save();

        $this->assertSame($folder->id, $doc->fresh()->folder_id);
    }

    public function test_another_companys_folders_are_invisible(): void
    {
        $this->folder('Sales');

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)), 'name' => 'Other Sarl',
            'owner_id' => User::factory()->create()->id, 'currency' => 'XAF',
            'plan' => 'business', 'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, BusinessDocumentFolder::count());
    }
}
