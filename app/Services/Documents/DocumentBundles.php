<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentPackage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * §39 — a package downloaded as one file.
 *
 * ZIP only. The brief also asks for a combined PDF, which needs a
 * server-side PDF engine this product does not have: PDF is
 * `window.print()` today, and choosing an engine (Browsershot, Gotenberg, or
 * neither) is an infrastructure decision rather than something to settle
 * silently inside a bundle exporter. Noted in docs/GAP-ANALYSIS.md rather
 * than half-built.
 *
 * Nothing here modifies a source document — §39 says so explicitly, and a
 * ZIP is read-only by construction, which is part of why it is the half
 * worth shipping first.
 */
class DocumentBundles
{
    public function __construct(protected DocumentFiler $filer) {}

    /**
     * Writes a ZIP of every uploaded file in a package to a temporary path
     * and returns it. The caller is responsible for streaming and deleting
     * it — this service does not know whether it is answering a download, a
     * queue job, or a test.
     *
     * Documents with no uploaded file (ones composed from a template, which
     * live as text rather than as a stored file) are skipped rather than
     * failing the bundle: a package legitimately mixes both, and refusing
     * the whole download because one entry has no file attached would be
     * useless behaviour.
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
