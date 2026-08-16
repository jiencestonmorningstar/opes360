<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 5 — real PDF output.
 *
 * The one place the application turns a print view into a PDF file. Every
 * caller — the print endpoints, the share download, the signature completion
 * page — goes through here, so the rendering engine is a private detail of
 * this class. Today that engine is dompdf (pure PHP, no daemon, safe on
 * shared hosting); when the product moves to a VPS, swapping in headless
 * Chromium (Browsershot/Gotenberg) is a change to THIS CLASS ONLY — same
 * render()/download() signatures, same views, nothing else moves.
 *
 * dompdf constraints this class absorbs so callers never think about them:
 * paper size and orientation are explicit (dompdf will not infer them), and
 * embedded images must be local paths or data URIs — it must never fetch over
 * HTTP, so public-disk image URLs are inlined as data URIs before rendering.
 */
class Pdf
{
    /**
     * Render a blade view to PDF binary.
     */
    public function render(string $view, array $data = [], string $paper = 'a4', string $orientation = 'portrait'): string
    {
        $html = $this->html($view, $data);

        $pdf = app('dompdf.wrapper');
        $pdf->setPaper($paper, $orientation);
        $pdf->loadHTML($html);

        return $pdf->output();
    }

    /**
     * The composed HTML the engine is given — the same blade view the browser
     * print path renders, with remote-looking local images inlined. Public so
     * tests can assert content (watermarks, tenant names) at the render step
     * without parsing PDF internals.
     */
    public function html(string $view, array $data = []): string
    {
        return $this->inlineLocalImages(view($view, $data)->render());
    }

    /**
     * A download response with an ASCII-safe attachment filename.
     */
    public function download(string $view, array $data, string $filename, string $paper = 'a4', string $orientation = 'portrait'): Response
    {
        return response($this->render($view, $data, $paper, $orientation), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * "{reference} {title}" → "REF-123-service-agreement.pdf": ASCII-safe,
     * shell-safe, header-safe. Empty parts drop out; a fully unnameable
     * document still gets "document.pdf".
     */
    public static function filename(?string ...$parts): string
    {
        $name = collect($parts)
            ->map(fn (?string $part) => trim(Str::ascii((string) $part)))
            ->filter()
            ->implode(' ');

        $name = trim(preg_replace('/[^A-Za-z0-9]+/', '-', $name) ?? '', '-');

        return ($name !== '' ? $name : 'document').'.pdf';
    }

    /**
     * dompdf must not fetch images over HTTP (isRemoteEnabled stays off — a
     * print view rendering another host's URL would otherwise become a
     * server-side request). Images that live on the app's own public disk
     * (the company logo, mostly) are read from disk and inlined as data URIs;
     * any other http(s) image is dropped rather than fetched.
     */
    protected function inlineLocalImages(string $html): string
    {
        $publicBase = rtrim(Storage::disk('public')->url(''), '/');

        return preg_replace_callback(
            '/(<img[^>]+src=")(https?:\/\/[^"]+)(")/i',
            function (array $m) use ($publicBase) {
                $url = html_entity_decode($m[2]);

                if ($publicBase !== '' && str_starts_with($url, $publicBase.'/')) {
                    $path = rawurldecode(substr($url, strlen($publicBase) + 1));

                    if (Storage::disk('public')->exists($path)) {
                        $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

                        return $m[1].'data:'.$mime.';base64,'
                            .base64_encode((string) Storage::disk('public')->get($path)).$m[3];
                    }
                }

                // Unknown host or missing file: an empty pixel, never a fetch.
                return $m[1].'data:image/gif;base64,R0lGODlhAQABAAAAACw='.$m[3];
            },
            $html,
        ) ?? $html;
    }
}
