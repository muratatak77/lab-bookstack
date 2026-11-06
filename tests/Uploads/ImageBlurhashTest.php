<?php

namespace Tests\Uploads;

use BookStack\Uploads\Image;
use BookStack\Uploads\ImageResizer;
use BookStack\Uploads\ImageService;
use Tests\TestCase;

class ImageBlurhashTest extends TestCase
{
    public function test_gallery_image_upload_stores_blurhash()
    {
        $this->asEditor();
        $imgDetails = $this->files->uploadGalleryImageToPage($this, $this->entities->page(), 'compressed.png');

        $image = Image::findOrFail($imgDetails['response']->id);
        $this->files->deleteAtRelativePath($imgDetails['path']);

        $expected = $this->app->make(ImageResizer::class)
            ->blurhashFromImageData(file_get_contents($this->files->testFilePath('compressed.png')));
        $this->assertNotNull($image->blurhash);
        $this->assertEquals($expected, $image->blurhash);
        $this->assertEquals($image->blurhash, $imgDetails['response']->blurhash);
    }

    public function test_drawing_upload_stores_blurhash()
    {
        $page = $this->entities->page();
        $this->asEditor();

        $resp = $this->postJson('images/drawio', [
            'uploaded_to' => $page->id,
            'image'       => 'image/png;base64,' . base64_encode($this->files->pngImageData()),
        ]);
        $resp->assertOk();

        $image = Image::query()->where('type', '=', 'drawio')->firstOrFail();
        $this->files->deleteAtRelativePath($image->path);

        $this->assertNotNull($image->blurhash);
        $resp->assertJson(['blurhash' => $image->blurhash]);
    }

    public function test_blurhash_not_stored_for_non_gallery_image_types()
    {
        $this->asAdmin();
        $imageService = $this->app->make(ImageService::class);

        foreach (['user', 'cover_book', 'system'] as $type) {
            $image = $imageService->saveNew('my-image.png', $this->files->pngImageData(), $type);
            $this->files->deleteAtRelativePath($image->path);

            $this->assertNull($image->blurhash, "Blurhash unexpectedly set for {$type} image");
            $this->assertDatabaseHas('images', ['id' => $image->id, 'blurhash' => null]);
        }
    }

    public function test_upload_succeeds_if_blurhash_cannot_be_generated()
    {
        $this->asAdmin();
        $imageService = $this->app->make(ImageService::class);

        $image = $imageService->saveNew('broken-image.png', 'not-image-data', 'gallery', $this->entities->page()->id);
        $this->files->deleteAtRelativePath($image->path);

        $this->assertNull($image->blurhash);
        $this->assertDatabaseHas('images', ['id' => $image->id, 'blurhash' => null]);
    }

    public function test_replacing_image_file_updates_blurhash()
    {
        $this->asAdmin();
        $imgDetails = $this->files->uploadGalleryImageToPage($this, $this->entities->page());
        $image = Image::findOrFail($imgDetails['response']->id);
        $originalBlurhash = $image->blurhash;

        $resp = $this->call('PUT', "/images/{$image->id}/file", [], [], [
            'file' => $this->files->uploadedImage($imgDetails['name'], 'compressed.png'),
        ]);
        $resp->assertOk();
        $this->files->deleteAtRelativePath($imgDetails['path']);

        $image->refresh();
        $this->assertNotNull($image->blurhash);
        $this->assertNotEquals($originalBlurhash, $image->blurhash);
    }

    public function test_rebuilding_thumbnails_regenerates_blurhash()
    {
        $this->asEditor();
        $imgDetails = $this->files->uploadGalleryImageToPage($this, $this->entities->page());
        $image = Image::findOrFail($imgDetails['response']->id);
        $expectedBlurhash = $image->blurhash;

        $image->blurhash = null;
        $image->save();

        $resp = $this->put("/images/{$image->id}/rebuild-thumbnails");
        $resp->assertOk();
        $this->files->deleteAtRelativePath($imgDetails['path']);

        $this->assertNotNull($expectedBlurhash);
        $this->assertDatabaseHas('images', ['id' => $image->id, 'blurhash' => $expectedBlurhash]);
    }

    public function test_regenerate_blurhash_returns_false_and_clears_hash_if_file_missing()
    {
        $this->asAdmin();
        $imgDetails = $this->files->uploadGalleryImageToPage($this, $this->entities->page());
        $image = Image::findOrFail($imgDetails['response']->id);
        $this->assertNotNull($image->blurhash);

        $this->files->deleteAtRelativePath($imgDetails['path']);
        $result = $this->app->make(ImageService::class)->regenerateBlurhash($image);

        $this->assertFalse($result);
        $this->assertDatabaseHas('images', ['id' => $image->id, 'blurhash' => null]);
    }
}
