<?php

namespace App\Services;

use App\Models\Company;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

/**
 * Server-side QR rendering, SVG only.
 *
 * SVG because these codes end up embedded in print-resolution PDFs — a raster QR
 * at A3 letterhead scale looks amateur. Error correction defaults to H (30%): a
 * scuffed or partly obscured printed receipt must still scan, and level H is what
 * makes the centre logo overlay below safe. Callers printing very small — business
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
     * @param  Company|null  $brand  When given and the company has a logo, the
     *                               logo is stamped in the centre — the "always
     *                               have the company icon in the middle" branding
     *                               requirement. Silently omitted (never an error)
     *                               for a company with no logo, or when $level is
     *                               explicitly M: a centre mark at M-level recovery
     *                               risks the code not scanning at all, so this
     *                               only ever overlays on H (the default).
     */
    public function svg(
        string $content,
        int $size = 512,
        int $margin = 2,
        ?ErrorCorrectionLevel $level = null,
        ?Company $brand = null,
    ): string {
        $level ??= ErrorCorrectionLevel::H();

        $renderer = new ImageRenderer(
            new RendererStyle($size, $margin),
            new SvgImageBackEnd,
        );

        $svg = (new Writer($renderer))->writeString(
            $content,
            Encoder::DEFAULT_BYTE_MODE_ENCODING,
            $level,
        );

        if ($brand === null || (string) $level !== (string) ErrorCorrectionLevel::H()) {
            return $svg;
        }

        return $this->withCentreLogo($svg, $brand, $size);
    }

    /**
     * Overlays the company's logo in the centre, on a white backing plate so
     * the modules directly behind it stay legible against whatever the logo's
     * own background is. Sized to ~18% of the code's width — comfortably
     * under the ~20% ceiling level-H recovery can absorb without the code
     * failing to scan.
     */
    protected function withCentreLogo(string $svg, Company $company, int $size): string
    {
        $logo = $this->logoDataUri($company);

        if ($logo === null) {
            return $svg;
        }

        $badge = $size * 0.18;
        $plate = $badge * 1.18;
        $offset = ($size - $plate) / 2;
        $logoOffset = ($size - $badge) / 2;

        $overlay = sprintf(
            '<g><rect x="%.2F" y="%.2F" width="%.2F" height="%.2F" rx="%.2F" fill="#ffffff"/>'
            .'<image x="%.2F" y="%.2F" width="%.2F" height="%.2F" href="%s" preserveAspectRatio="xMidYMid meet"/></g>',
            $offset, $offset, $plate, $plate, $plate * 0.18,
            $logoOffset, $logoOffset, $badge, $badge, $logo,
        );

        return str_replace('</svg>', $overlay.'</svg>', $svg);
    }

    /**
     * The logo as a self-contained data: URI, so the QR SVG never depends on
     * a second network request to render correctly — the same reasoning
     * every other embedded-in-a-printed-page image in this product follows.
     * Null for no logo, an unreadable file, or a type this cannot safely
     * inline; never an exception; a QR that fails to gain a logo still
     * scans perfectly well without one.
     */
    protected function logoDataUri(Company $company): ?string
    {
        if (! $company->logo_path) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($company->logo_path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($company->logo_path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };

        if ($mime === null) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($company->logo_path));
    }
}
