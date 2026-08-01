<?php

namespace App\Services;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Server-side QR rendering, SVG only.
 *
 * SVG because these codes end up embedded in print-resolution PDFs — a raster QR
 * at A3 letterhead scale looks amateur. Error correction defaults to H (30%): a
 * scuffed or partly obscured printed receipt must still scan, and level H is what
 * later makes a centre logo overlay safe. Callers printing very small — business
 * cards — pass M instead; see the note on svg(). docs/architecture/qr-ar.md.
 */
class QrCodes
{
    /**
     * @param  ErrorCorrectionLevel|null  $level  Defaults to H (30%). Pass M for
     *                                            codes printed very small — a business card's QR is barely 13mm square, and
     *                                            H spends so many modules on recovery that each one falls under the ~0.4mm
     *                                            a phone camera needs. M keeps 15% recovery with a third fewer modules,
     *                                            which is the difference between a card that scans and one that does not.
     */
    public function svg(string $content, int $size = 512, int $margin = 2, ?ErrorCorrectionLevel $level = null): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString(
            $content,
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            $level ?? ErrorCorrectionLevel::H(),
        );
    }
}
