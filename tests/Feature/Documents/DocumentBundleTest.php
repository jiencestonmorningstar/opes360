<?php

namespace Tests\Feature\Documents;

use App\Models\BusinessDocumentPackage;
use App\Services\Documents\DocumentBundles;
use App\Services\Documents\DocumentDossiers;
use App\Services\Documents\DocumentFiler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class DocumentBundleTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DocumentFiler::DISK);
    }

    public function test_a_package_of_uploaded_files_zips(): void
    {
        $package = $this->packageWith(['first.pdf', 'second.pdf']);

        $path = app(DocumentBundles::class)->zipPackage($package->fresh());

        $this->assertFileExists($path);
        $this->assertSame(2, $this->entryCount($path));

        @unlink($path);
    }

    /**
     * A package legitimately mixes uploaded files with documents composed
     * from a template, which have no stored file. Refusing the whole
     * download because of one would be useless behaviour.
     */
    public function test_a_composed_document_with_no_file_is_skipped_rather_than_failing(): void
    {
        $package = $this->packageWith(['only.pdf']);
        app(DocumentDossiers::class)->addToPackage($package->fresh(), $this->document(['title' => 'Composed, no file']));

        $path = app(DocumentBundles::class)->zipPackage($package->fresh());

        $this->assertSame(1, $this->entryCount($path));

        @unlink($path);
    }

    public function test_an_empty_package_still_produces_an_archive(): void
    {
        $package = BusinessDocumentPackage::create([
            'name' => 'Nothing yet', 'status' => 'open', 'created_by' => $this->owner->id,
        ]);

        $path = app(DocumentBundles::class)->zipPackage($package);

        $this->assertFileExists($path);

        @unlink($path);
    }

    /** Two suppliers' "certificate.pdf" must not silently become one entry. */
    public function test_duplicate_filenames_are_kept_distinct(): void
    {
        $package = $this->packageWith(['certificate.pdf', 'certificate.pdf']);

        $path = app(DocumentBundles::class)->zipPackage($package->fresh());

        $this->assertSame(2, $this->entryCount($path));

        @unlink($path);
    }

    /** @param array<int, string> $filenames */
    protected function packageWith(array $filenames): BusinessDocumentPackage
    {
        $package = BusinessDocumentPackage::create([
            'name' => 'Bundle test', 'status' => 'open', 'created_by' => $this->owner->id,
        ]);

        $filer = app(DocumentFiler::class);
        $dossiers = app(DocumentDossiers::class);

        foreach ($filenames as $filename) {
            $document = $filer->upload(
                UploadedFile::fake()->create($filename, 10, 'application/pdf'),
                $this->owner,
            );

            $dossiers->addToPackage($package->fresh(), $document);
        }

        return $package;
    }

    protected function entryCount(string $path): int
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $count = $zip->numFiles;
        $zip->close();

        return $count;
    }
}
