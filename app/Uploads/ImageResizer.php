<?php

namespace BookStack\Uploads;

use BookStack\Exceptions\ImageUploadException;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Colors\Rgb\Channels\Blue;
use Intervention\Image\Colors\Rgb\Channels\Green;
use Intervention\Image\Colors\Rgb\Channels\Red;
use Intervention\Image\Colors\Rgb\Colorspace as RgbColorspace;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\AutoEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Interfaces\ImageInterface as InterventionImage;
use Intervention\Image\ImageManager;
use kornrunner\Blurhash\Blurhash;

class ImageResizer
{
    protected const THUMBNAIL_CACHE_TIME = 604_800; // 1 week

    /**
     * The max width/height, in pixels, of the image sampled when generating a blurhash.
     * The hash only holds a few components, so a small sample keeps generation fast
     * without any noticeable change in the output.
     */
    protected const BLURHASH_SAMPLE_SIZE = 32;

    public function __construct(
        protected ImageStorage $storage,
    ) {
    }

    /**
     * Load gallery thumbnails for a set of images.
     * @param iterable<Image> $images
     */
    public function loadGalleryThumbnailsForMany(iterable $images, bool $shouldCreate = false): void
    {
        foreach ($images as $image) {
            $this->loadGalleryThumbnailsForImage($image, $shouldCreate);
        }
    }

    /**
     * Load gallery thumbnails into the given image instance.
     */
    public function loadGalleryThumbnailsForImage(Image $image, bool $shouldCreate): void
    {
        $thumbs = ['gallery' => null, 'display' => null];

        try {
            $thumbs['gallery'] = $this->resizeToThumbnailUrl($image, 150, 150, false, $shouldCreate);
            $thumbs['display'] = $this->resizeToThumbnailUrl($image, 1680, null, true, $shouldCreate);
        } catch (Exception $exception) {
            // Prevent thumbnail errors from stopping execution
        }

        $image->setAttribute('thumbs', $thumbs);
    }

    /**
     * Get the thumbnail for an image.
     * If $keepRatio is true, only the width will be used.
     * Checks the cache then storage to avoid creating / accessing the filesystem on every check.
     *
     * @throws Exception
     */
    public function resizeToThumbnailUrl(
        Image $image,
        ?int $width,
        ?int $height,
        bool $keepRatio = false,
        bool $shouldCreate = false
    ): ?string {
        // Do not resize GIF images where we're not cropping
        if ($keepRatio && $this->isGif($image)) {
            return $this->storage->getPublicUrl($image->path);
        }

        $thumbDirName = '/' . ($keepRatio ? 'scaled-' : 'thumbs-') . $width . '-' . $height . '/';
        $imagePath = $image->path;
        $thumbFilePath = dirname($imagePath) . $thumbDirName . basename($imagePath);

        $thumbCacheKey = 'images::' . $image->id . '::' . $thumbFilePath;

        // Return path if in cache
        $cachedThumbPath = Cache::get($thumbCacheKey);
        if ($cachedThumbPath && !$shouldCreate) {
            return $this->storage->getPublicUrl($cachedThumbPath);
        }

        // If a thumbnail has already been generated, serve that and cache path
        $disk = $this->storage->getDisk($image->type);
        if (!$shouldCreate && $disk->exists($thumbFilePath)) {
            Cache::put($thumbCacheKey, $thumbFilePath, static::THUMBNAIL_CACHE_TIME);

            return $this->storage->getPublicUrl($thumbFilePath);
        }

        $imageData = $disk->get($imagePath);

        // Do not resize animated images where we're not cropping
        if ($keepRatio && $this->isAnimated($image, $imageData)) {
            Cache::put($thumbCacheKey, $image->path, static::THUMBNAIL_CACHE_TIME);

            return $this->storage->getPublicUrl($image->path);
        }

        // If not in cache and thumbnail does not exist, generate thumb and cache path
        $thumbData = $this->resizeImageData($imageData, $width, $height, $keepRatio, $this->getExtension($image));
        $disk->put($thumbFilePath, $thumbData, true);
        Cache::put($thumbCacheKey, $thumbFilePath, static::THUMBNAIL_CACHE_TIME);

        return $this->storage->getPublicUrl($thumbFilePath);
    }

    /**
     * Resize the image of given data to the specified size and return the new image data.
     * Format will remain the same as the input format, unless specified.
     *
     * @throws ImageUploadException
     */
    public function resizeImageData(
        string $imageData,
        ?int $width,
        ?int $height,
        bool $keepRatio,
        ?string $format = null,
    ): string {
        try {
            $thumb = $this->interventionFromImageData($imageData);
        } catch (Exception $e) {
            Log::error('Failed to resize image with error:' . $e->getMessage());
            throw new ImageUploadException(trans('errors.cannot_create_thumbs'));
        }

        if ($keepRatio) {
            $thumb->scaleDown($width, $height);
        } else {
            $thumb->cover($width, $height);
        }

        $encoder = match ($format) {
            'png' => new PngEncoder(),
            default => new AutoEncoder(),
        };

        $thumbData = (string) $thumb->encode($encoder);

        // Use original image data if we're keeping the ratio
        // and the resizing does not save any space.
        if ($keepRatio && strlen($thumbData) > strlen($imageData)) {
            return $imageData;
        }

        return $thumbData;
    }

    /**
     * Create an intervention image instance from the given image data.
     * The image is explicitly decoded from binary data so that the data cannot be
     * misinterpreted as another source type (like a file path).
     * Orientation from EXIF data is applied, where possible, upon decoding.
     * GIF images are decoded natively, as a single frame, since we don't need the
     * added animation support.
     */
    protected function interventionFromImageData(string $imageData): InterventionImage
    {
        if (!extension_loaded('gd')) {
            throw new ImageUploadException('The PHP "gd" extension is required to resize images, but is missing.');
        }

        $manager = new ImageManager(
            new Driver(),
            autoOrientation: true,
            decodeAnimation: false,
        );

        return $manager->decodeBinary($imageData);
    }

    /**
     * Generate a blurhash string for the given image data, which can be used to
     * represent a blurred placeholder of the image while the actual image loads.
     * Returns null if the image data could not be processed.
     */
    public function blurhashFromImageData(string $imageData): ?string
    {
        try {
            $image = $this->interventionFromImageData($imageData);
            $image->scaleDown(static::BLURHASH_SAMPLE_SIZE, static::BLURHASH_SAMPLE_SIZE);
            $image->fillTransparentAreas('ffffff');
            $pixels = $this->getRgbPixelsForImage($image);
        } catch (Exception $e) {
            Log::error('Failed to read image for blurhash generation with error:' . $e->getMessage());
            return null;
        }

        // Use more components along the longest side to better match the image shape
        $isLandscape = $image->width() >= $image->height();
        $componentsX = $isLandscape ? 4 : 3;
        $componentsY = $isLandscape ? 3 : 4;

        return Blurhash::encode($pixels, $componentsX, $componentsY);
    }

    /**
     * Get the RGB values for each pixel of the given image, as an array of rows,
     * where each row is an array of [red, green, blue] values.
     *
     * @return array<int, array<int, int[]>>
     */
    protected function getRgbPixelsForImage(InterventionImage $image): array
    {
        $width = $image->width();
        $height = $image->height();
        $pixels = [];

        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $color = $image->colorAt($x, $y)->toColorspace(RgbColorspace::class);
                $row[] = [
                    $color->channel(Red::class)->value(),
                    $color->channel(Green::class)->value(),
                    $color->channel(Blue::class)->value(),
                ];
            }
            $pixels[] = $row;
        }

        return $pixels;
    }

    /**
     * Checks if the image is a GIF. Returns true if it is, else false.
     */
    protected function isGif(Image $image): bool
    {
        return $this->getExtension($image) === 'gif';
    }

    /**
     * Get the extension for the given image, normalised to lower-case.
     */
    protected function getExtension(Image $image): string
    {
        return strtolower(pathinfo($image->path, PATHINFO_EXTENSION));
    }

    /**
     * Check if the given image and image data is apng.
     */
    protected function isApngData(string &$imageData): bool
    {
        $initialHeader = substr($imageData, 0, strpos($imageData, 'IDAT'));

        return str_contains($initialHeader, 'acTL');
    }

    /**
     * Check if the given avif image data represents an animated image.
     * This is based upon the answer here: https://stackoverflow.com/a/79457313
     */
    protected function isAnimatedAvifData(string &$imageData): bool
    {
        $stszPos = strpos($imageData, 'stsz');
        if ($stszPos === false) {
            return false;
        }

        // Look 12 bytes after the start of 'stsz'
        $start = $stszPos + 12;
        $end = $start + 4;
        if ($end > strlen($imageData) - 1) {
            return false;
        }

        $data = substr($imageData, $start, 4);
        $count = unpack('Nvalue', $data)['value'];
        return $count > 1;
    }

    /**
     * Check if the given image is animated.
     */
    protected function isAnimated(Image $image, string &$imageData): bool
    {
        $extension = strtolower(pathinfo($image->path, PATHINFO_EXTENSION));
        if ($extension === 'png') {
            return $this->isApngData($imageData);
        }

        if ($extension === 'avif') {
            return $this->isAnimatedAvifData($imageData);
        }

        return false;
    }
}
