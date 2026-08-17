<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The one gate every uploaded file passes before it is kept.
 *
 * Every upload path in the product — managed documents, CVs from the public
 * vacancy page, logos and photos, CSV/XLSX imports, bank statements — calls
 * accept() with a purpose, and the purpose decides what the file may be:
 * which extensions, which sniffed mime types, and how large.
 *
 * Three disciplines, in order:
 *
 *   1. The extension must be on the purpose's allow-list. An allow-list, not
 *      a block-list — a block-list of dangerous extensions is a list somebody
 *      always finds a gap in.
 *   2. The mime type sniffed from the file's actual bytes must agree with the
 *      extension. The client's claimed type is guessed from the filename, so
 *      checking it would be circular; getMimeType() reads the bytes, which is
 *      what "the contents do not match the name" has to mean if renaming a
 *      script to .pdf is going to be refused.
 *   3. If a ClamAV daemon is reachable, the bytes are streamed to it and a
 *      FOUND verdict refuses the file. When no daemon is configured — shared
 *      hosting, a dev machine — this step is a documented no-op and the gate
 *      degrades gracefully to the sniff checks above.
 *
 * A refusal is always a RuntimeException whose message can be shown to the
 * person uploading. On public paths the message deliberately never mentions
 * that a scanner exists — "we scan uploads with X" is a datasheet for whoever
 * is probing the endpoint.
 *
 * ── ClamAV configuration ────────────────────────────────────────────────────
 *
 * Set CLAMAV_SOCKET in .env to wherever clamd listens:
 *
 *   CLAMAV_SOCKET=unix:///var/run/clamav/clamd.ctl   (the VPS)
 *   CLAMAV_SOCKET=tcp://127.0.0.1:3310
 *
 * Leave it unset anywhere clamd does not run. The protocol spoken is clamd's
 * own INSTREAM (the file's bytes framed in length-prefixed chunks over the
 * socket), so no package and no shell-out to clamscan is involved, and the
 * file never has to be readable by the clamav user.
 */
class UploadGate
{
    /** How much of the file goes to clamd per INSTREAM chunk. */
    protected const CHUNK_BYTES = 8192;

    /**
     * What each purpose accepts: extension => sniffed mime types that may
     * wear it, plus a size cap and the noun used in refusal messages.
     *
     * @var array<string, array{max_bytes: int, noun: string, allowed: array<string, array<int, string>>}>
     */
    public const PURPOSES = [
        // Managed documents: what a business genuinely files.
        'document' => [
            'max_bytes' => 25 * 1024 * 1024,
            'noun' => 'file',
            'allowed' => [
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
            ],
        ],

        // A CV from the public vacancy page: the document list narrowed to the
        // shapes a CV actually takes. No spreadsheets — an .xlsx "CV" on a
        // public endpoint is an attack surface, not a résumé.
        'cv' => [
            'max_bytes' => 10 * 1024 * 1024,
            'noun' => 'CV',
            'allowed' => [
                'pdf' => ['application/pdf'],
                'doc' => ['application/msword'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
                'odt' => ['application/vnd.oasis.opendocument.text'],
                'txt' => ['text/plain'],
                'png' => ['image/png'],
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
            ],
        ],

        // Logos and photos: images only, and only the shapes GD or a browser
        // can actually render.
        'image' => [
            'max_bytes' => 4 * 1024 * 1024,
            'noun' => 'image',
            'allowed' => [
                'png' => ['image/png'],
                'jpg' => ['image/jpeg'],
                'jpeg' => ['image/jpeg'],
                'webp' => ['image/webp'],
                'gif' => ['image/gif'],
                // Laravel's `image` rule accepted bmp before the gate existed;
                // narrowing here would refuse a logo that used to work.
                'bmp' => ['image/bmp', 'image/x-ms-bmp'],
            ],
        ],

        // Record imports and bank statements: tabular files only.
        'import' => [
            'max_bytes' => 5 * 1024 * 1024,
            'noun' => 'import file',
            'allowed' => [
                'csv' => ['text/csv', 'text/plain', 'application/csv'],
                'txt' => ['text/plain', 'text/csv'],
                'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
                'xlsx' => [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    // A bare xlsx built by another tool often sniffs as plain zip.
                    'application/zip',
                ],
            ],
        ],
    ];

    /**
     * The whole gate: extension, sniffed mime, size, then the scanner.
     *
     * @throws RuntimeException with a reason fit to show the uploader
     */
    public function accept(UploadedFile $file, string $purpose): void
    {
        $rules = self::PURPOSES[$purpose] ?? null;

        if ($rules === null) {
            throw new RuntimeException("There is no [{$purpose}] upload purpose.");
        }

        if (! $file->isValid()) {
            throw new RuntimeException('That file did not upload correctly. Try again.');
        }

        if ($file->getSize() > $rules['max_bytes']) {
            throw new RuntimeException(
                'A '.$rules['noun'].' must be smaller than '.($rules['max_bytes'] / 1024 / 1024).' MB.'
            );
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (! array_key_exists($extension, $rules['allowed'])) {
            throw new RuntimeException(
                $extension === ''
                    ? 'That file has no extension, so what it is cannot be told.'
                    : 'A '.$rules['noun'].' cannot be a .'.$extension.' file.'
            );
        }

        $mime = strtolower((string) $file->getMimeType());

        if (! in_array($mime, $rules['allowed'][$extension], true)) {
            throw new RuntimeException("That file's contents do not match its name.");
        }

        $this->scan($file);
    }

    /**
     * Stream the bytes to clamd and refuse on FOUND.
     *
     * A documented no-op when CLAMAV_SOCKET is unset, and deliberately
     * fail-open when the daemon is configured but unreachable: the sniff
     * checks above already ran, and a restarting daemon must not take the
     * whole intake down with it. The outage is logged so it gets fixed
     * rather than silently becoming the permanent state.
     */
    public function scan(UploadedFile $file): void
    {
        $socket = $this->socket();

        if ($socket === null) {
            return;
        }

        $stream = @$this->connect($socket);

        if ($stream === false || $stream === null) {
            Log::warning('UploadGate: clamd is configured but unreachable; upload passed on sniff checks alone.', [
                'socket' => $socket,
            ]);

            return;
        }

        try {
            $this->writeInstream($stream, (string) $file->getRealPath());
            $verdict = $this->readVerdict($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (str_contains($verdict, 'FOUND')) {
            Log::warning('UploadGate: clamd refused an upload.', [
                'verdict' => $verdict,
                'name' => $file->getClientOriginalName(),
            ]);

            // Nothing here names the scanner: a public page repeating this
            // message must not tell whoever is probing what stands behind it.
            throw new RuntimeException('That file could not be accepted. Please try a different file.');
        }
    }

    /**
     * Where clamd listens, or null when no scanner is configured.
     *
     * config(), not env(): this used to be a raw env() call — the only one in
     * app/ — which reads null after `php artisan config:cache` runs, and that
     * cache is part of every documented production deploy. The one place raw
     * env() would have disabled scanning was production itself.
     */
    public function socket(): ?string
    {
        $socket = (string) config('services.clamav.socket', '');

        return $socket === '' ? null : $socket;
    }

    /** @return resource|false */
    protected function connect(string $socket)
    {
        return stream_socket_client($socket, $errno, $error, 5.0);
    }

    /**
     * clamd's INSTREAM command: "zINSTREAM\0", then the file as
     * <4-byte big-endian length><chunk> frames, ended by a zero-length frame.
     *
     * @param  resource  $stream
     */
    protected function writeInstream($stream, string $path): void
    {
        fwrite($stream, "zINSTREAM\0");

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            fwrite($stream, pack('N', 0));

            return;
        }

        while (! feof($handle)) {
            $chunk = fread($handle, self::CHUNK_BYTES);

            if ($chunk === false || $chunk === '') {
                break;
            }

            fwrite($stream, pack('N', strlen($chunk)).$chunk);
        }

        fclose($handle);
        fwrite($stream, pack('N', 0));
    }

    /**
     * clamd's one-line answer: "stream: OK" or "stream: <signature> FOUND".
     *
     * @param  resource  $stream
     */
    protected function readVerdict($stream): string
    {
        return trim((string) fgets($stream, 512));
    }
}
