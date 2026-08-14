<?php

namespace Tests\Feature;

use App\Services\LogoProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cleaning up a logo on the way in.
 *
 * The tests that matter are the refusals. Removing a background is guessing,
 * and the interesting question is not whether it works on a white box — it is
 * whether it declines to touch the images where guessing would eat the logo.
 */
class LogoProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    protected function processor(): LogoProcessor
    {
        return app(LogoProcessor::class);
    }

    /** Alpha at a point, 0 opaque … 127 fully transparent. */
    protected function alphaAt(string $path, int $x, int $y): int
    {
        $image = imagecreatefrompng(Storage::disk('public')->path($path));
        $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;
        imagedestroy($image);

        return $alpha;
    }

    protected function upload(\GdImage $image, string $name = 'logo.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'logo').'.png';
        imagepng($image, $tmp);
        imagedestroy($image);

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    /** A mark on a white box: the box goes. */
    protected function markOnWhite(): \GdImage
    {
        $image = imagecreatetruecolor(400, 300);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledellipse($image, 200, 150, 180, 180, imagecolorallocate($image, 29, 78, 216));

        return $image;
    }

    public function test_a_white_background_is_removed(): void
    {
        $result = $this->processor()->store($this->upload($this->markOnWhite()), 'acme');

        $this->assertTrue($result['cleaned']);
        $this->assertGreaterThan(100, $this->alphaAt($result['path'], 0, 0));
    }

    /**
     * The one that would have hurt. A white background and the white inside a
     * letter O are the same colour; only one of them touches the border. Clear
     * every matching pixel and the logo comes back with a hole punched in it.
     */
    public function test_white_enclosed_by_the_mark_survives(): void
    {
        $image = $this->markOnWhite();
        // Punch a white hole in the middle of the disc, like the counter of an O.
        imagefilledellipse($image, 200, 150, 80, 80, imagecolorallocate($image, 255, 255, 255));

        $result = $this->processor()->store($this->upload($image), 'acme');

        $size = getimagesize(Storage::disk('public')->path($result['path']));
        $centreX = (int) ($size[0] / 2);
        $centreY = (int) ($size[1] / 2);

        $this->assertTrue($result['cleaned']);
        $this->assertLessThan(20, $this->alphaAt($result['path'], $centreX, $centreY));
    }

    /** The empty border goes with it, so the mark fills its box. */
    public function test_the_border_is_trimmed_away(): void
    {
        $result = $this->processor()->store($this->upload($this->markOnWhite()), 'acme');

        [$width, $height] = getimagesize(Storage::disk('public')->path($result['path']));

        // The disc is 180px across inside a 400×300 canvas.
        $this->assertLessThan(200, $width);
        $this->assertLessThan(200, $height);
    }

    /**
     * Four corners that disagree mean a photograph or a gradient, and
     * flood-filling one corner of those tears a hole in the picture.
     */
    public function test_a_photographic_background_is_left_alone(): void
    {
        $image = imagecreatetruecolor(200, 200);

        for ($x = 0; $x < 200; $x++) {
            for ($y = 0; $y < 200; $y++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $x % 256, $y % 256, 128));
            }
        }

        $result = $this->processor()->store($this->upload($image), 'acme');

        $this->assertFalse($result['cleaned']);
    }

    /** Somebody has already done this properly; second-guessing can only lose. */
    public function test_an_image_that_is_already_transparent_is_left_alone(): void
    {
        $image = imagecreatetruecolor(200, 200);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, 100, 100, 120, 120, imagecolorallocate($image, 29, 78, 216));

        $result = $this->processor()->store($this->upload($image), 'acme');

        $this->assertFalse($result['cleaned']);
    }

    /** A signboard photograph must not be shipped to every customer in full. */
    public function test_an_oversized_upload_is_bounded(): void
    {
        $image = imagecreatetruecolor(3000, 2000);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 100, 100, 2900, 1900, imagecolorallocate($image, 29, 78, 216));

        $result = $this->processor()->store($this->upload($image), 'acme');

        [$width, $height] = getimagesize(Storage::disk('public')->path($result['path']));

        $this->assertLessThanOrEqual(LogoProcessor::MAX_WIDTH, $width);
        $this->assertLessThanOrEqual(LogoProcessor::MAX_HEIGHT, $height);
    }

    /** Small logos are not blown up: that is blur at a larger pixel count. */
    public function test_a_small_logo_is_not_upscaled(): void
    {
        $image = imagecreatetruecolor(120, 60);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 10, 10, 110, 50, imagecolorallocate($image, 29, 78, 216));

        $result = $this->processor()->store($this->upload($image), 'acme');

        [$width] = getimagesize(Storage::disk('public')->path($result['path']));

        $this->assertLessThanOrEqual(120, $width);
    }

    /**
     * A dark background, which is the case that catches a flood fill relying on
     * "a filled pixel turns black and so stops matching". Against a black
     * background it would keep matching and never terminate.
     */
    public function test_a_dark_background_is_removed_without_hanging(): void
    {
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        imagefilledellipse($image, 150, 100, 120, 120, imagecolorallocate($image, 255, 214, 0));

        $result = $this->processor()->store($this->upload($image), 'acme');

        $this->assertTrue($result['cleaned']);
        $this->assertGreaterThan(100, $this->alphaAt($result['path'], 0, 0));
    }

    /** The untouched upload is kept, so a bad clean-up can be handed back. */
    public function test_the_original_upload_is_kept(): void
    {
        $result = $this->processor()->store($this->upload($this->markOnWhite()), 'acme');

        Storage::disk('public')->assertExists($result['original_path']);
        $this->assertNotSame($result['path'], $result['original_path']);
    }

    /**
     * An SVG is not something GD reads, and refusing the upload would be worse
     * than storing what arrived — the templates render it fine.
     */
    public function test_a_file_gd_cannot_read_is_stored_untouched(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';
        $tmp = tempnam(sys_get_temp_dir(), 'logo').'.svg';
        file_put_contents($tmp, $svg);

        $result = $this->processor()->store(
            new UploadedFile($tmp, 'logo.svg', 'image/svg+xml', null, true),
            'acme'
        );

        $this->assertFalse($result['cleaned']);
        $this->assertSame($result['path'], $result['original_path']);
        Storage::disk('public')->assertExists($result['path']);
    }
}
