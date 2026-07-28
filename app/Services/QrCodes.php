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
 * at A3 letterhead scale looks amateur. Error correction is fixed at H (30%): a
 * scuffed or partly obscured printed receipt must still scan, and level H is what
 * later makes a centre logo overlay safe. See docs/architecture/qr-ar.md.
 */
class QrCodes
{
    public function svg(string $content, int $size = 512, int $margin = 2): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new SvgImageBackEnd,
        );

        return (new Writer($renderer))->writeString(
            $content,
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            ErrorCorrectionLevel::H(),
        );
    }
}
