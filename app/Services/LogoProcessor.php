<?php

namespace App\Services;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Cleans up a logo the moment it is uploaded.
 *
 * ── The problem ─────────────────────────────────────────────────────────────
 *
 * A business does not have a design department. What arrives is a JPEG
 * screenshotted from a Word document, or a PNG exported with a white box
 * around it, or a 4000px photograph of a signboard. Dropped onto an invoice
 * unchanged, that white box sits as a grey rectangle on the letterhead, and
 * the 4000px file is downloaded in full by every customer opening the PDF.
 *
 * So the upload is cleaned once, on the way in, rather than every template
 * being asked to cope.
 *
 *   1. If the edges are one uniform colour, that colour becomes transparent.
 *   2. The now-empty border is trimmed away.
 *   3. The result is bounded to a sane size and written as a PNG with alpha.
 *
 * ── What it refuses to do ───────────────────────────────────────────────────
 *
 * Background removal is guessing, and a wrong guess eats the logo. So it only
 * runs when the guess is safe, and each refusal below is a real logo it would
 * otherwise have damaged:
 *
 *   • An image that already has transparency is left alone — somebody has
 *     already done this properly and second-guessing them can only lose.
 *   • The four corners must agree. A photograph or a gradient background does
 *     not have four matching corners, and flood-filling one would tear a hole
 *     in the middle of the picture.
 *   • Only the region connected to the edge is cleared, never every matching
 *     pixel in the image. A white background and the white inside a letter O
 *     are the same colour; only one of them touches the border.
 *
 * The original upload is kept alongside the cleaned file, so a business whose
 * logo came out wrong can be given it back without asking them to find it
 * again.
 */
class LogoProcessor
{
    /** The cleaned logo fits inside this box. Wide enough for a banner
     *  wordmark at print resolution, small enough not to bloat a PDF. */
    public const MAX_WIDTH = 900;

    public const MAX_HEIGHT = 400;

    /**
     * How far a pixel may differ from the sampled background and still count
     * as background. Generous enough for JPEG artefacts around a white box,
     * tight enough to leave a pale-grey logo mark standing.
     */
    public const TOLERANCE = 32;

    /**
     * Process an upload and return the paths written.
     *
     * @return array{path: string, original_path: string, cleaned: bool}
     */
    public function store(UploadedFile $file, string $companyId, string $disk = 'public'): array
    {
        $directory = 'logos/'.$companyId;

        // The untouched upload, kept so the cleaning can be undone.
        $originalPath = $file->store($directory.'/original', $disk);

        $image = $this->read(Storage::disk($disk)->path($originalPath));

        if ($image === null) {
            // Not something GD can read (an SVG, say). Store it as it came and
            // let the templates render it directly — better than refusing an
            // upload that would have worked.
            return ['path' => $originalPath, 'original_path' => $originalPath, 'cleaned' => false];
        }

        /*
         * Bounded first, then cleaned.
         *
         * The order is not cosmetic: the flood fill visits every pixel it can
         * reach, and a 3000×2000 signboard photograph is six million of them.
         * Capping the size first means the expensive pass runs over at most
         * MAX_WIDTH × MAX_HEIGHT no matter what was uploaded.
         */
        $image = $this->bound($image);

        $cleaned = false;

        if ($this->shouldRemoveBackground($image)) {
            $image = $this->removeBackground($image);
            $cleaned = true;
        }

        $image = $this->trim($image);

        $path = $directory.'/logo-'.now()->timestamp.'.png';

        ob_start();
        imagesavealpha($image, true);
        imagepng($image, null, 6);
        Storage::disk($disk)->put($path, (string) ob_get_clean());

        imagedestroy($image);

        return ['path' => $path, 'original_path' => $originalPath, 'cleaned' => $cleaned];
    }

    protected function read(string $path): ?GdImage
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_GIF => @imagecreatefromgif($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if ($image === false) {
            return null;
        }

        // Work in true colour with alpha from here on, whatever came in.
        $canvas = $this->blankCanvas(imagesx($image), imagesy($image));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        imagedestroy($image);

        return $canvas;
    }

    protected function blankCanvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);

        return $canvas;
    }

    /**
     * Whether removing the background is safe — see the class docblock for why
     * each of these refusals exists.
     */
    protected function shouldRemoveBackground(GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width < 8 || $height < 8) {
            return false;
        }

        $corners = [
            imagecolorat($image, 0, 0),
            imagecolorat($image, $width - 1, 0),
            imagecolorat($image, 0, $height - 1),
            imagecolorat($image, $width - 1, $height - 1),
        ];

        // Already transparent anywhere at the edge: somebody has done this.
        foreach ($corners as $corner) {
            if ((($corner >> 24) & 0x7F) > 8) {
                return false;
            }
        }

        $first = $corners[0];

        foreach ($corners as $corner) {
            if ($this->distance($first, $corner) > self::TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clear the background, starting from the edges.
     *
     * A scanline flood fill rather than imagefilltoborder: only pixels
     * *connected to the border* go, so the white inside a letter O survives
     * while the white around the mark does not.
     */
    protected function removeBackground(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $target = imagecolorat($image, 0, 0);

        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagealphablending($image, false);

        /*
         * A scanline fill, not a per-pixel one.
         *
         * The naive version pushes four neighbours for every pixel it visits
         * and only checks whether they were already seen after popping them,
         * so the queue grows to several entries per pixel — on a large image
         * that exhausts memory before it finishes. This walks whole horizontal
         * runs at a time and pushes one seed per adjacent run instead, which
         * keeps the stack proportional to the shape of the region rather than
         * to its area.
         *
         * `$done` is marked when a pixel is *filled*, so a run is never walked
         * twice.
         */
        $done = [];
        $stack = [];

        // Every edge pixel is a seed: a logo bleeding off one side still leaves
        // background attached to the others.
        for ($x = 0; $x < $width; $x++) {
            $stack[] = [$x, 0];
            $stack[] = [$x, $height - 1];
        }

        for ($y = 0; $y < $height; $y++) {
            $stack[] = [0, $y];
            $stack[] = [$width - 1, $y];
        }

        /*
         * `$done` is captured by reference, and that is load-bearing rather
         * than tidiness. Captured by value the guard would always see an empty
         * array, and the fill would only ever terminate because a filled pixel
         * turns black and so stops matching a white background. On a logo whose
         * background is already black, filled pixels would keep matching and it
         * would loop until it ran out of memory.
         */
        $matches = function (int $x, int $y) use ($image, $target, &$done, $width): bool {
            return ! isset($done[$y * $width + $x])
                && $this->distance(imagecolorat($image, $x, $y), $target) <= self::TOLERANCE;
        };

        while ($stack !== []) {
            [$seedX, $seedY] = array_pop($stack);

            if ($seedY < 0 || $seedY >= $height || ! $matches($seedX, $seedY)) {
                continue;
            }

            // Walk left and right to the ends of this run.
            $left = $seedX;
            while ($left > 0 && $matches($left - 1, $seedY)) {
                $left--;
            }

            $right = $seedX;
            while ($right < $width - 1 && $matches($right + 1, $seedY)) {
                $right++;
            }

            // Fill it, and seed the rows above and below once per adjacent run
            // rather than once per pixel.
            $aboveOpen = false;
            $belowOpen = false;

            for ($x = $left; $x <= $right; $x++) {
                imagesetpixel($image, $x, $seedY, $transparent);
                $done[$seedY * $width + $x] = true;

                if ($seedY > 0) {
                    $open = $matches($x, $seedY - 1);
                    if ($open && ! $aboveOpen) {
                        $stack[] = [$x, $seedY - 1];
                    }
                    $aboveOpen = $open;
                }

                if ($seedY < $height - 1) {
                    $open = $matches($x, $seedY + 1);
                    if ($open && ! $belowOpen) {
                        $stack[] = [$x, $seedY + 1];
                    }
                    $belowOpen = $open;
                }
            }
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    /** Crop away a fully transparent border, so the mark fills its box. */
    protected function trim(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 120) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        // Nothing visible — a fully transparent upload. Leave it be rather
        // than cropping to nothing.
        if ($maxX < 0) {
            return $image;
        }

        $cropped = $this->blankCanvas($maxX - $minX + 1, $maxY - $minY + 1);

        imagealphablending($cropped, false);
        imagecopy($cropped, $image, 0, 0, $minX, $minY, $maxX - $minX + 1, $maxY - $minY + 1);
        imagealphablending($cropped, true);

        imagedestroy($image);

        return $cropped;
    }

    /**
     * Fit inside the box, never beyond the native size.
     *
     * Upscaling a small logo would only make it blurry at a larger number of
     * pixels — the letterhead already scales what it is given.
     */
    protected function bound(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $scale = min(self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height, 1);

        if ($scale >= 1) {
            return $image;
        }

        $target = $this->blankCanvas((int) round($width * $scale), (int) round($height * $scale));

        imagealphablending($target, false);
        imagecopyresampled(
            $target, $image,
            0, 0, 0, 0,
            imagesx($target), imagesy($target),
            $width, $height
        );
        imagealphablending($target, true);

        imagedestroy($image);

        return $target;
    }

    /** Straight-line distance between two colours, ignoring alpha. */
    protected function distance(int $a, int $b): float
    {
        return sqrt(
            ((($a >> 16) & 0xFF) - (($b >> 16) & 0xFF)) ** 2
            + ((($a >> 8) & 0xFF) - (($b >> 8) & 0xFF)) ** 2
            + ((($a) & 0xFF) - (($b) & 0xFF)) ** 2
        );
    }
}
