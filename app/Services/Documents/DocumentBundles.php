<?php

namespace App\Services\Documents;

use App\Jobs\BuildDocumentBundle;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentBundle;
use App\Models\BusinessDocumentPackage;
use App\Models\Media;
use App\Models\User;
use App\Services\DocumentComposer;
use App\Support\CurrentCompany;
use App\Support\DocumentTemplates;
use App\Support\Pdf;
use App\Support\Watermarks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * §39 — a package downloaded as one file.
 *
 * ZIP of every document. Uploaded files go in as-is; documents composed from
 * a template (which live as text, not as a stored file) are rendered to PDF
 * through App\Support\Pdf — Phase 5's engine — so a package no longer
 * silently drops its composed half.
 *
 * Nothing here modifies a source document — §39 says so explicitly, and a
 * ZIP is read-only by construction, which is part of why it is the half
 * worth shipping first.
 */
class DocumentBundles
{
    /**
     * §60 — where "large" starts. Past either number, building the ZIP
     * inline would hold an HTTP request (and, on shared PHP-FPM hosting, a
     * worker process) open long enough to risk a gateway timeout for
     * something that has nothing to do with request/response latency.
     *
     * 20 files: comfortably more than any package a person assembles by
     * hand in one sitting, but well below a bulk selection across a whole
     * folder. 20 MB: a handful of scanned, multi-page contracts — the
     * uploads DocumentFiler itself caps at 25 MB each, so three or four of
     * those already clears it.
     *
     * Both are argued thresholds, not measured ones: there is no production
     * traffic yet to tune them against. They only matter when a real queue
     * is configured — see request() below — so getting them slightly wrong
     * costs a slower request on a queued install, never a broken one.
     */
    public const LARGE_FILE_COUNT = 20;

    public const LARGE_BYTES = 20 * 1024 * 1024;

    public function __construct(protected DocumentFiler $filer) {}

    /**
     * The one entry point bulk/package downloads should call from now on.
     *
     * Under the `sync` queue connection — the default, and what shared
     * hosting without a worker runs — a queued dispatch executes inline
     * anyway, but *inside* this call, at the depth the retry-avoidance
     * lesson from DeliverWebhook teaches: don't let a job whose only escape
     * hatch is "queue it for later" pretend that escape hatch exists when
     * nothing is polling the queue. So on `sync` this always returns a
     * ready path, built right here, exactly as it always did.
     *
     * With a real queue configured, a selection over either threshold is
     * handed to BuildDocumentBundle and this returns the pending record
     * instead of a path — the caller (a Livewire action) tells the user it
     * is on its way rather than blocking the click.
     *
     * @param  Collection<int, BusinessDocument>  $documents
     * @return string|BusinessDocumentBundle a path when built synchronously, the pending record when queued
     */
    public function request(Collection $documents, User $actor)
    {
        $company = app(CurrentCompany::class)->get();

        if (config('queue.default') === 'sync' || $company === null || ! $this->isLarge($documents)) {
            return $this->zipDocuments($documents);
        }

        $bundle = BusinessDocumentBundle::create([
            'company_id' => $company->id,
            'created_by' => $actor->id,
            'status' => 'pending',
            'document_count' => $documents->count(),
        ]);

        BuildDocumentBundle::dispatch($bundle->id, $company->id, $documents->pluck('id')->all());

        return $bundle;
    }

    /**
     * @param  Collection<int, BusinessDocument>  $documents
     */
    public function isLarge(Collection $documents): bool
    {
        if ($documents->count() > self::LARGE_FILE_COUNT) {
            return true;
        }

        $bytes = Media::query()
            ->where('collection', 'document')
            ->where('attachable_type', (new BusinessDocument)->getMorphClass())
            ->whereIn('attachable_id', $documents->pluck('id'))
            ->sum('size');

        return $bytes > self::LARGE_BYTES;
    }

    /**
     * Writes a ZIP of every uploaded file in a package to a temporary path
     * and returns it. The caller is responsible for streaming and deleting
     * it — this service does not know whether it is answering a download, a
     * queue job, or a test.
     *
     * Documents with no uploaded file (ones composed from a template, which
     * live as text rather than as a stored file) are rendered to PDF on the
     * way in; only a document with neither a file nor a body is skipped —
     * refusing the whole download because one entry has nothing to give
     * would be useless behaviour.
     */
    public function zipPackage(BusinessDocumentPackage $package): string
    {
        return $this->zipDocuments($package->documents);
    }

    /**
     * The same packager for an arbitrary set of documents — §64–68's bulk
     * download runs through here so a package download and a workspace
     * selection produce byte-for-byte the same kind of archive.
     *
     * @param  iterable<int, BusinessDocument>  $documents
     */
    public function zipDocuments(iterable $documents): string
    {
        $path = storage_path('app/'.Str::uuid().'.zip');

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the bundle archive.');
        }

        $used = [];

        foreach ($documents as $document) {
            $media = $this->filer->fileOf($document);

            if ($media === null) {
                // Composed from a template: no stored file, but Phase 5's PDF
                // engine can produce one from the same print view the browser
                // uses — watermarks included, so a draft in a bundle still
                // says DRAFT.
                if (trim((string) $document->body) !== '') {
                    $zip->addFromString(
                        $this->uniqueName($document, Pdf::filename($document->reference, $document->title), $used),
                        $this->composedPdf($document),
                    );
                }

                continue;
            }

            // The media row names its own disk; documents live on the private
            // one but reading the column rather than assuming keeps this
            // correct if that ever differs.
            $disk = Storage::disk($media->disk ?? DocumentFiler::DISK);

            if (! $disk->exists($media->path)) {
                continue;
            }

            $zip->addFromString(
                $this->uniqueName($document, basename($media->path), $used),
                (string) $disk->get($media->path),
            );
        }

        /*
         * ZipArchive does not write an archive to disk at all if nothing was
         * added to it, so an empty package would return a path to a file
         * that does not exist. A caller streaming that would 500 on a
         * package that is simply empty, which is a legitimate state.
         */
        if ($zip->numFiles === 0) {
            $zip->addFromString('README.txt', "This package contains no downloadable files.\n");
        }

        $zip->close();

        return $path;
    }

    /**
     * A composed document rendered to PDF, through the same view and marks
     * the print route uses. The bundle is an export, so the confidential
     * footer names the exporting user when one is signed in.
     */
    protected function composedPdf(BusinessDocument $document): string
    {
        return app(Pdf::class)->render('print.paper', [
            'watermark' => Watermarks::statusMark($document),
            'confidentialFooter' => Watermarks::confidentialFooter(
                $document,
                app(CurrentCompany::class)->get(),
                auth()->user()?->name ?? 'Bundle export',
            ),
            'paper' => $document,
            'company' => app(CurrentCompany::class)->get(),
            'bodyHtml' => app(DocumentComposer::class)->toHtml($document->body),
            'notice' => ($document->template()['binding'] ?? false)
                ? DocumentTemplates::reviewNotice()
                : null,
            'qrSvg' => null,
            'autoprint' => false,
        ]);
    }

    /**
     * Two documents in one package can legitimately carry the same filename
     * — two suppliers' "certificate.pdf". A ZIP with a duplicate entry name
     * silently loses one of them, so the second gets a numeric suffix.
     *
     * @param  array<string, int>  $used
     */
    protected function uniqueName(BusinessDocument $document, string $filename, array &$used): string
    {
        $name = $filename;

        if (! isset($used[$name])) {
            $used[$name] = 1;

            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = pathinfo($name, PATHINFO_FILENAME);
        $suffixed = $base.' ('.(++$used[$name]).')'.($extension !== '' ? '.'.$extension : '');

        return $suffixed;
    }
}
