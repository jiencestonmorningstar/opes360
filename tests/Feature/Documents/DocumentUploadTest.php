<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentFolder;
use App\Models\Company;
use App\Models\Media;
use App\Models\User;
use App\Services\Documents\DocumentFiler;
use App\Support\CurrentCompany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentUploadTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DocumentFiler::DISK);
    }

    protected function filer(): DocumentFiler
    {
        return app(DocumentFiler::class);
    }

    protected function pdf(string $name = 'contract.pdf', int $kb = 40): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    public function test_an_upload_becomes_a_managed_document(): void
    {
        $doc = $this->filer()->upload($this->pdf(), $this->owner);

        $this->assertInstanceOf(BusinessDocument::class, $doc);
        $this->assertSame(1, BusinessDocument::count());
        $this->assertSame(1, Media::count());
    }

    public function test_the_file_lands_on_the_private_disk_not_the_public_one(): void
    {
        $doc = $this->filer()->upload($this->pdf(), $this->owner);

        $media = $this->filer()->fileOf($doc);

        $this->assertNotNull($media);
        $this->assertSame('documents', $media->disk, 'a contract must not be on the public disk');
        Storage::disk(DocumentFiler::DISK)->assertExists($media->path);
    }

    /** The private disk must have no public URL at all. */
    public function test_the_documents_disk_is_not_publicly_addressable(): void
    {
        $this->assertNull(config('filesystems.disks.documents.url'));
        $this->assertSame('private', config('filesystems.disks.documents.visibility'));
    }

    /** Files are namespaced per company, so one tenant's path cannot collide. */
    public function test_files_are_stored_under_the_company(): void
    {
        $doc = $this->filer()->upload($this->pdf(), $this->owner);

        $this->assertStringStartsWith('c/'.$this->company->id.'/', $this->filer()->fileOf($doc)->path);
    }

    public function test_the_checksum_is_recorded(): void
    {
        $doc = $this->filer()->upload($this->pdf(), $this->owner);

        $this->assertSame(64, strlen((string) $this->filer()->fileOf($doc)->checksum));
    }

    public function test_the_title_comes_from_the_filename_when_none_is_given(): void
    {
        $doc = $this->filer()->upload($this->pdf('supply_agreement_2026.pdf'), $this->owner);

        $this->assertSame('supply agreement 2026', $doc->title);
    }

    public function test_a_given_title_wins(): void
    {
        $doc = $this->filer()->upload($this->pdf(), $this->owner, ['title' => 'Supply agreement']);

        $this->assertSame('Supply agreement', $doc->title);
    }

    public function test_metadata_is_carried_through(): void
    {
        $doc = $this->filer()->upload($this->pdf(), $this->owner, [
            'kind' => 'contract',
            'security' => 'confidential',
            'tags' => ['signed'],
        ]);

        $this->assertSame('contract', $doc->kind);
        $this->assertTrue($doc->isConfidential());
        $this->assertSame(['signed'], $doc->tags);
    }

    public function test_an_unknown_kind_falls_back_rather_than_being_stored(): void
    {
        $doc = $this->filer()->upload($this->pdf(), $this->owner, ['kind' => 'nonsense']);

        $this->assertSame('document', $doc->kind);
    }

    public function test_an_upload_can_go_straight_into_a_folder(): void
    {
        $folder = BusinessDocumentFolder::create(['name' => 'Contracts', 'kind' => 'company']);

        $doc = $this->filer()->upload($this->pdf(), $this->owner, [], $folder);

        $this->assertSame($folder->id, $doc->folder_id);
    }

    public function test_the_uploader_becomes_the_owner(): void
    {
        $doc = $this->filer()->upload($this->pdf(), $this->owner);

        $this->assertSame($this->owner->id, $doc->owner_id);
    }

    // ── What is refused ──────────────────────────────────────────────────

    public function test_an_unrecognised_extension_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->filer()->upload(
            UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'),
            $this->owner,
        );
    }

    public function test_a_php_file_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->filer()->upload(
            UploadedFile::fake()->create('shell.php', 2, 'application/x-php'),
            $this->owner,
        );
    }

    /** Renaming a file is the oldest trick there is. */
    public function test_a_file_whose_contents_disagree_with_its_name_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->filer()->upload(
            UploadedFile::fake()->create('invoice.pdf', 10, 'application/x-php'),
            $this->owner,
        );
    }

    public function test_an_oversized_file_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        $this->filer()->upload($this->pdf('huge.pdf', 30 * 1024), $this->owner);
    }

    public function test_nothing_is_stored_when_a_file_is_refused(): void
    {
        try {
            $this->filer()->upload(
                UploadedFile::fake()->create('payload.exe', 10, 'application/octet-stream'),
                $this->owner,
            );
        } catch (RuntimeException) {
            // expected
        }

        $this->assertSame(0, BusinessDocument::count());
        $this->assertSame(0, Media::count());
    }

    public function test_uploading_without_a_company_is_refused(): void
    {
        app(CurrentCompany::class)->set(null);

        $this->expectException(RuntimeException::class);

        $this->filer()->upload($this->pdf(), $this->owner);
    }

    // ── Duplicates ───────────────────────────────────────────────────────

    /**
     * Reported, never acted on. A business genuinely files the same PDF
     * against two customers, and refusing the second would lose a document
     * somebody deliberately kept.
     */
    public function test_a_duplicate_is_flagged_but_still_stored(): void
    {
        // createWithContent, not create: a faked file made with create() is
        // zero bytes whatever size it reports, so two different "files" would
        // hash identically and the test would pass for the wrong reason.
        $body = '%PDF-1.4 identical terms';

        $first = $this->filer()->upload(
            UploadedFile::fake()->createWithContent('terms.pdf', $body),
            $this->owner,
        );
        $checksum = $this->filer()->fileOf($first)->checksum;

        $second = $this->filer()->upload(
            UploadedFile::fake()->createWithContent('terms.pdf', $body),
            $this->owner,
        );

        $this->assertSame(2, BusinessDocument::count(), 'the duplicate should still be stored');

        $duplicates = $this->filer()->duplicatesOf($checksum, $second->id);

        $this->assertCount(1, $duplicates);
        $this->assertSame($first->id, $duplicates->first()->id);
    }

    public function test_a_different_file_is_not_a_duplicate(): void
    {
        $a = $this->filer()->upload(
            UploadedFile::fake()->createWithContent('a.pdf', '%PDF-1.4 first'),
            $this->owner,
        );
        $this->filer()->upload(
            UploadedFile::fake()->createWithContent('b.pdf', '%PDF-1.4 second, quite different'),
            $this->owner,
        );

        $this->assertCount(0, $this->filer()->duplicatesOf(
            $this->filer()->fileOf($a)->checksum,
            $a->id,
        ));
    }

    public function test_another_companys_uploads_are_invisible(): void
    {
        $this->filer()->upload($this->pdf(), $this->owner);

        $other = Company::create([
            'slug' => 'other-'.Str::lower(Str::random(6)),
            'name' => 'Other Sarl',
            'owner_id' => User::factory()->create()->id,
            'currency' => 'XAF', 'plan' => 'business', 'account_type' => 'active',
        ]);

        app(CurrentCompany::class)->set($other);

        $this->assertSame(0, BusinessDocument::count());
        $this->assertSame(0, Media::count());
    }
}
