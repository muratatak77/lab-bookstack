<?php

namespace Tests\Uploads;

use BookStack\Exceptions\ImageUploadException;
use BookStack\Uploads\ImageResizer;
use GdImage;
use Tests\TestCase;

class ImageResizerTest extends TestCase
{
    protected ImageResizer $resizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resizer = $this->app->make(ImageResizer::class);
    }

    public function test_resize_applies_exif_rotation()
    {
        $this->requireExifExtension();
        // Original image is 40x20, left half red, right half blue, with an EXIF orientation of 6 (rotate 90 CW).
        $originalData = file_get_contents($this->files->testFilePath('exif-orientation-6.jpg'));

        $resized = $this->gdFromData($this->resizer->resizeImageData($originalData, 20, 40, false));

        $this->assertEquals(20, imagesx($resized));
        $this->assertEquals(40, imagesy($resized));
        $this->assertPixelIsMostly('red', $resized, 10, 2);
        $this->assertPixelIsMostly('blue', $resized, 10, 37);
    }

    public function test_resize_applies_exif_horizontal_mirroring()
    {
        $this->requireExifExtension();
        // Original image is 40x20, left half red, right half blue, with an EXIF orientation of 2 (mirror horizontal).
        $originalData = file_get_contents($this->files->testFilePath('exif-orientation-2.jpg'));

        $resized = $this->gdFromData($this->resizer->resizeImageData($originalData, 40, 20, false));

        $this->assertEquals(40, imagesx($resized));
        $this->assertEquals(20, imagesy($resized));
        $this->assertPixelIsMostly('blue', $resized, 2, 10);
        $this->assertPixelIsMostly('red', $resized, 37, 10);
    }

    public function test_resize_with_kept_ratio_returns_original_data_if_not_smaller()
    {
        $originalData = file_get_contents($this->files->testFilePath('compressed.png'));

        $resized = $this->resizer->resizeImageData($originalData, 1680, null, true);

        $this->assertEquals($originalData, $resized);
    }

    public function test_resize_keeps_original_format_by_default()
    {
        $originalData = file_get_contents($this->files->testFilePath('test-image.jpg'));

        $resized = $this->resizer->resizeImageData($originalData, 20, 20, false);

        $this->assertEquals('image/jpeg', getimagesizefromstring($resized)['mime']);
    }

    public function test_resize_can_output_png_format()
    {
        $originalData = file_get_contents($this->files->testFilePath('test-image.jpg'));

        $resized = $this->resizer->resizeImageData($originalData, 32, 32, false, 'png');

        $details = getimagesizefromstring($resized);
        $this->assertEquals('image/png', $details['mime']);
        $this->assertEquals([32, 32], [$details[0], $details[1]]);
    }

    public function test_resize_of_animated_gif_outputs_single_frame_gif()
    {
        $originalData = file_get_contents($this->files->testFilePath('animated.gif'));

        $resized = $this->resizer->resizeImageData($originalData, 20, 20, false, 'gif');

        $this->assertEquals('image/gif', getimagesizefromstring($resized)['mime']);
        $this->assertLessThan(2, count(explode("\x00\x21\xF9", $resized)) - 1);
    }

    public function test_resize_of_invalid_data_throws_upload_exception()
    {
        $this->expectException(ImageUploadException::class);
        $this->resizer->resizeImageData('this is not image data', 20, 20, false);
    }

    public function test_blurhash_generated_for_image_data()
    {
        $originalData = file_get_contents($this->files->testFilePath('test-image.jpg'));

        $blurhash = $this->resizer->blurhashFromImageData($originalData);

        // Landscape image, so 4x3 components: 1 (size) + 1 (max AC) + 4 (DC) + (11 AC * 2) characters.
        $this->assertNotNull($blurhash);
        $this->assertEquals(28, strlen($blurhash));
        $this->assertStringStartsWith('L', $blurhash);
        $this->assertEquals($blurhash, $this->resizer->blurhashFromImageData($originalData));
    }

    public function test_blurhash_components_follow_image_orientation()
    {
        $this->requireExifExtension();
        $landscapeData = file_get_contents($this->files->testFilePath('exif-orientation-2.jpg'));
        $portraitData = file_get_contents($this->files->testFilePath('exif-orientation-6.jpg'));

        // The first character encodes the component counts: 'L' = 4x3, 'T' = 3x4
        $this->assertStringStartsWith('L', $this->resizer->blurhashFromImageData($landscapeData));
        $this->assertStringStartsWith('T', $this->resizer->blurhashFromImageData($portraitData));
    }

    public function test_blurhash_returns_null_for_invalid_image_data()
    {
        $this->assertNull($this->resizer->blurhashFromImageData('this is not image data'));
    }

    protected function requireExifExtension(): void
    {
        if (!extension_loaded('exif')) {
            $this->markTestSkipped('The PHP "exif" extension is required for orientation handling');
        }
    }

    protected function gdFromData(string $imageData): GdImage
    {
        $image = imagecreatefromstring($imageData);
        $this->assertNotFalse($image, 'Failed to read image data');

        return $image;
    }

    /**
     * Assert the given pixel is mostly of the given colour.
     * Used to allow for minor differences due to compression.
     */
    protected function assertPixelIsMostly(string $color, GdImage $image, int $x, int $y): void
    {
        $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
        $channel = $color === 'red' ? 'red' : 'blue';
        $otherChannel = $color === 'red' ? 'blue' : 'red';

        $this->assertGreaterThan(200, $rgb[$channel], "Expected pixel at {$x},{$y} to be {$color}");
        $this->assertLessThan(55, $rgb[$otherChannel], "Expected pixel at {$x},{$y} to be {$color}");
    }
}
