<?php

namespace App\Services\Documents;

use App\Models\BusinessDocument;
use App\Models\BusinessDocumentFolder;
use App\Models\Media;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\DocumentKinds;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Taking a file in and making it a managed document.
 *
 * The file itself goes to the `documents` disk, which is private and has no
 * URL. It is reachable only through a controller that checks the policy first —
 * a signed contract must not be one guessed filename away from the public.
 *
 * The row goes through the existing `media` table rather than a new one. That
 * table already carries disk, path, mime, size and checksum, and adding a
 * second file store beside it is exactly the duplication this module is meant
 * to avoid.
 */
class DocumentFiler
{
    public const DISK = 'documents';

    /** 25 MB. Large enough for a scanned contract, small enough to survive a bad connection. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /**
     * What may be uploaded.
     *
     * An allow-list, not a block-list. A block-list of dangerous extensions is
     * a list somebody always finds a gap in; this refuses everything it does
     * not recognise, which is the only version that stays correct.
     *
     * @var array<string, array<int, string>> extension => acceptable mime types
     */
    public const ALLOWED = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'txt' => ['text/plain'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'odt' => ['application/vnd.oasis.opendocument.text'],
    ];

    /**
     * Store an uploaded file as a document.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function upload(
        UploadedFile $file,
        User $actor,
        array $attributes = [],
        ?BusinessDocumentFolder $folder = null,
    ): BusinessDocument {
        $company = app(CurrentCompany::class)->get();

        if ($company === null) {
            throw new RuntimeException('Cannot file a document without a current company.');
        }

        $this->assertAcceptable($file);

        $checksum = hash_file('sha256', $file->getRealPath());

        return DB::transaction(function () use ($file, $actor, $attributes, $folder, $company, $checksum) {
            $document = BusinessDocument::create([
                // No template: an uploaded file is not composed from one, and
                // pretending otherwise would put it in the compose screen.
                'template' => null,
                'title' => trim((string) ($attributes['title'] ?? '')) ?: $this->titleFrom($file),
                'description' => $attributes['description'] ?? null,
                'kind' => DocumentKinds::exists($attributes['kind'] ?? null) ? $attributes['kind'] : 'document',
                'security' => $attributes['security'] ?? 'internal',
                'language' => $attributes['language'] ?? null,
                'tags' => $attributes['tags'] ?? null,
                'folder_id' => $folder?->id,
                'reference' => 'DOC-'.Str::upper(Str::random(8)),
                'recipient' => $attributes['recipient'] ?? null,
                'fields' => null,
                'body' => null,
                'status' => 'draft',
                'expires_on' => $attributes['expires_on'] ?? null,
                'owner_id' => $actor->id,
                'created_by' => $actor->id,
            ]);

            $path = $file->store('c/'.$company->id, self::DISK);

            if ($path === false) {
                throw new RuntimeException('The file could not be stored.');
            }

            Media::create([
                'company_id' => $company->id,
                'collection' => 'document',
                'disk' => self::DISK,
                'path' => $path,
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'checksum' => $checksum,
                'attachable_type' => $document->getMorphClass(),
                'attachable_id' => $document->id,
                'sort_order' => 0,
            ]);

            return $document->fresh();
        });
    }

    /**
     * Documents in this company whose file matches a checksum.
     *
     * Reported, never acted on. A business genuinely does hold the same PDF
     * against two customers, and silently refusing — or worse, deleting — the
     * second one would lose a document somebody deliberately filed.
     *
     * @return Collection<int, BusinessDocument>
     */
    public function duplicatesOf(string $checksum, ?string $exceptDocumentId = null)
    {
        $ids = Media::query()
            ->where('collection', 'document')
            ->where('checksum', $checksum)
            ->when($exceptDocumentId !== null, fn ($q) => $q->where('attachable_id', '!=', $exceptDocumentId))
            ->pluck('attachable_id');

        return BusinessDocument::query()->whereIn('id', $ids)->get();
    }

    public function fileOf(BusinessDocument $document): ?Media
    {
        return Media::query()
            ->where('attachable_type', $document->getMorphClass())
            ->where('attachable_id', $document->id)
            ->where('collection', 'document')
            ->first();
    }

    /**
     * Both the extension and the reported mime type must be recognised, and
     * they must agree. Either alone is trivially spoofed by renaming a file.
     */
    protected function assertAcceptable(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new RuntimeException('That file did not upload correctly. Try again.');
        }

        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('That file is larger than '.(self::MAX_BYTES / 1024 / 1024).' MB.');
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! array_key_exists($extension, self::ALLOWED)) {
            throw new RuntimeException("Files of type .{$extension} cannot be uploaded.");
        }

        /*
         * getMimeType(), not getClientMimeType().
         *
         * The client type is guessed from the filename, so checking it against
         * the extension is circular — it agrees by construction and catches
         * nothing. It is also supplied by whoever is uploading, which makes it
         * the last thing to trust. getMimeType() inspects the file's actual
         * bytes, which is what "the contents do not match the name" needs to
         * mean if renaming a script to .pdf is going to be refused.
         */
        $mime = strtolower((string) $file->getMimeType());

        if (! in_array($mime, self::ALLOWED[$extension], true)) {
            throw new RuntimeException('That file\'s contents do not match its name.');
        }
    }

    protected function titleFrom(UploadedFile $file): string
    {
        $name = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);

        return Str::limit(trim(str_replace(['_', '-'], ' ', $name)) ?: 'Untitled document', 180, '');
    }
}
