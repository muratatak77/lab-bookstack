<?php

namespace Tests\Commands;

use BookStack\Uploads\Image;
use Tests\TestCase;

class RegenerateImageBlurhashesCommandTest extends TestCase
{
    public function test_command_generates_missing_blurhashes()
    {
        $this->asAdmin();
        $imgDetails = $this->files->uploadGalleryImageToPage($this, $this->entities->page());
        $image = Image::findOrFail($imgDetails['response']->id);
        $expectedBlurhash = $image->blurhash;
        $this->assertNotNull($expectedBlurhash);

        Image::query()->update(['blurhash' => null]);

        $this->artisan('bookstack:regenerate-image-blurhashes')
            ->expectsOutputToContain('Blurhashes generated for 1 image(s)')
            ->assertExitCode(0);

        $this->files->deleteAtRelativePath($imgDetails['path']);
        $this->assertDatabaseHas('images', ['id' => $image->id, 'blurhash' => $expectedBlurhash]);
    }

    public function test_command_only_targets_images_without_blurhash_by_default()
    {
        $this->asAdmin();
        $imgDetails = $this->files->uploadGalleryImageToPage($this, $this->entities->page());
        $image = Image::findOrFail($imgDetails['response']->id);
        $image->blurhash = 'existing-hash';
        $image->save();

        $this->artisan('bookstack:regenerate-image-blurhashes')
            ->expectsOutputToContain('Generating blurhashes for 0 image(s)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('images', ['id' => $image->id, 'blurhash' => 'existing-hash']);

        $this->artisan('bookstack:regenerate-image-blurhashes --all')
            ->expectsOutputToContain('Blurhashes generated for 1 image(s)')
            ->assertExitCode(0);

        $this->files->deleteAtRelativePath($imgDetails['path']);
        $image->refresh();
        $this->assertNotNull($image->blurhash);
        $this->assertNotEquals('existing-hash', $image->blurhash);
    }

    public function test_command_ignores_non_gallery_image_types()
    {
        $image = Image::factory()->create(['type' => 'user', 'path' => '/uploads/images/user/2026-11/avatar.png']);

        $this->artisan('bookstack:regenerate-image-blurhashes')
            ->expectsOutputToContain('Generating blurhashes for 0 image(s)')
            ->assertExitCode(0);

        $this->assertDatabaseHas('images', ['id' => $image->id, 'blurhash' => null]);
    }

    public function test_command_reports_images_which_could_not_be_processed()
    {
        $image = Image::factory()->create(['type' => 'gallery', 'path' => '/uploads/images/gallery/2026-11/missing.png']);

        $this->artisan('bookstack:regenerate-image-blurhashes')
            ->expectsOutputToContain("Failed to generate blurhash for image {$image->id} at path {$image->path}")
            ->expectsOutputToContain('1 image(s) could not be processed')
            ->assertExitCode(1);

        $this->assertDatabaseHas('images', ['id' => $image->id, 'blurhash' => null]);
    }
}
