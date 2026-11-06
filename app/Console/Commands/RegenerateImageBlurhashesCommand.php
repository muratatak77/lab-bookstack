<?php

namespace BookStack\Console\Commands;

use BookStack\Uploads\Image;
use BookStack\Uploads\ImageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class RegenerateImageBlurhashesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookstack:regenerate-image-blurhashes
                            {--a|all : Regenerate for all images, including those which already have a blurhash}
                            {--database= : The database connection to use}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate the blurhash placeholders for gallery images and drawings';

    /**
     * Execute the console command.
     */
    public function handle(ImageService $imageService): int
    {
        $connection = DB::getDefaultConnection();

        if ($this->option('database')) {
            DB::setDefaultConnection($this->option('database'));
        }

        $query = Image::query()->whereIn('type', ImageService::getBlurhashTypes());
        if (!$this->option('all')) {
            $query->whereNull('blurhash');
        }

        $total = (clone $query)->count();
        $generated = 0;
        $failed = 0;

        $this->comment("Generating blurhashes for {$total} image(s)...");

        $query->chunkById(100, function (Collection $images) use ($imageService, &$generated, &$failed) {
            /** @var Image $image */
            foreach ($images as $image) {
                if ($imageService->regenerateBlurhash($image)) {
                    $generated++;
                    continue;
                }

                $failed++;
                $this->line("Failed to generate blurhash for image {$image->id} at path {$image->path}");
            }
        });

        DB::setDefaultConnection($connection);

        $this->comment("Blurhashes generated for {$generated} image(s)");
        if ($failed > 0) {
            $this->warn("{$failed} image(s) could not be processed");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
