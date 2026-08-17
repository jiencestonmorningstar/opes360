<?php

namespace Tests\Feature\Documents;

use App\Jobs\BuildDocumentBundle;
use App\Models\BusinessDocumentBundle;
use App\Notifications\DocumentBundleReadyNotification;
use App\Services\Documents\BulkActions;
use App\Services\Documents\DocumentBundles;
use App\Services\Documents\DocumentFiler;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * §60 — background bundling stays sync-safe.
 *
 * Every branch here mirrors Wave 3's DeliverWebhook lesson: nothing may
 * assume a worker is running. The `sync` branch (this app's default, and
 * shared hosting's reality) must behave exactly as it did before this
 * feature existed — a path, built inline, in the same request. Only with a
 * queue actually configured does a large selection defer.
 */
class DocumentBundleQueueTest extends DocumentsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(DocumentFiler::DISK);
    }

    public function test_under_sync_a_large_selection_still_returns_a_path_built_inline(): void
    {
        Config::set('queue.default', 'sync');

        $documents = $this->documentsWithFiles(25);

        $result = app(DocumentBundles::class)->request($documents, $this->owner);

        $this->assertIsString($result);
        $this->assertFileExists($result);

        @unlink($result);
    }

    public function test_a_small_selection_is_never_queued_even_with_a_real_queue_configured(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();

        $documents = $this->documentsWithFiles(3);

        $result = app(DocumentBundles::class)->request($documents, $this->owner);

        $this->assertIsString($result);
        Queue::assertNotPushed(BuildDocumentBundle::class);

        @unlink($result);
    }

    public function test_a_large_selection_with_a_real_queue_configured_defers_to_a_job(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();

        $documents = $this->documentsWithFiles(25);

        $result = app(DocumentBundles::class)->request($documents, $this->owner);

        $this->assertInstanceOf(BusinessDocumentBundle::class, $result);
        $this->assertSame('pending', $result->status);
        $this->assertSame($this->company->id, $result->company_id);
        $this->assertSame($this->owner->id, $result->created_by);

        Queue::assertPushed(BuildDocumentBundle::class, function (BuildDocumentBundle $job) use ($result, $documents) {
            return $job->bundleId === $result->id
                && $job->companyId === $this->company->id
                && count($job->documentIds) === $documents->count();
        });
    }

    public function test_a_selection_over_the_byte_threshold_defers_even_under_twenty_files(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();

        $filer = app(DocumentFiler::class);
        $document = $filer->upload(
            UploadedFile::fake()->create('huge.pdf', 21 * 1024, 'application/pdf'),
            $this->owner,
        );

        $result = app(DocumentBundles::class)->request(collect([$document]), $this->owner);

        $this->assertInstanceOf(BusinessDocumentBundle::class, $result);
        Queue::assertPushed(BuildDocumentBundle::class);
    }

    public function test_the_job_builds_the_zip_and_notifies_the_requester_when_done(): void
    {
        Notification::fake();

        $documents = $this->documentsWithFiles(2);

        $bundle = BusinessDocumentBundle::create([
            'company_id' => $this->company->id,
            'created_by' => $this->owner->id,
            'status' => 'pending',
            'document_count' => $documents->count(),
        ]);

        (new BuildDocumentBundle($bundle->id, $this->company->id, $documents->pluck('id')->all()))
            ->handle(app(DocumentBundles::class));

        $bundle->refresh();

        $this->assertSame('ready', $bundle->status);
        $this->assertNotNull($bundle->path);
        $this->assertTrue(Storage::disk(DocumentFiler::DISK)->exists($bundle->path));

        $zip = new ZipArchive;
        $zip->open(Storage::disk(DocumentFiler::DISK)->path($bundle->path));
        $this->assertSame(2, $zip->numFiles);
        $zip->close();

        Notification::assertSentTo($this->owner, DocumentBundleReadyNotification::class);
    }

    public function test_bulk_actions_zip_reaches_the_same_queueing_decision(): void
    {
        Config::set('queue.default', 'database');
        Queue::fake();

        $documents = $this->documentsWithFiles(25);

        $result = app(BulkActions::class)->zip($documents, $this->owner);

        $this->assertInstanceOf(BusinessDocumentBundle::class, $result);
        Queue::assertPushed(BuildDocumentBundle::class);
    }

    protected function documentsWithFiles(int $count)
    {
        $filer = app(DocumentFiler::class);

        $documents = collect();

        for ($i = 0; $i < $count; $i++) {
            $documents->push($filer->upload(
                UploadedFile::fake()->create("file{$i}.pdf", 10, 'application/pdf'),
                $this->owner,
            ));
        }

        return $documents;
    }
}
