<?php

namespace App\Services;

use App\Values\ImageWritingConfig;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Intervention\Image\FileExtension;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Interfaces\ImageManagerInterface;
use Intervention\Image\Laravel\Facades\Image;
use RuntimeException;
use Throwable;

class ImageWriter
{
    private FileExtension $extension;

    public function __construct()
    {
        $this->extension = self::getExtension();
    }

    private static function getExtension(): FileExtension
    {
        /** @var ImageManagerInterface $manager */
        $manager = Image::getFacadeRoot();

        // Prioritize AVIF over WEBP over JPEG.
        foreach ([FileExtension::AVIF, FileExtension::WEBP, FileExtension::JPEG] as $extension) {
            if ($manager->driver()->supports($extension)) {
                return $extension;
            }
        }

        throw new RuntimeException('No supported image extension found.');
    }

    public function write(string $destination, mixed $source, ?ImageWritingConfig $config = null): void
    {
        if (Str::isUrl($source)) {
            try {
                $source = Http::withUserAgent(http_user_agent())
                    ->get($source)
                    ->throwIfClientError()
                    ->throwIfServerError()
                    ->body();
            } catch (Throwable $e) {
                throw new RuntimeException('Failed to fetch image from URL: ' . $source, previous: $e);
            }
        }

        $img = Image::read($source);

        if ($config instanceof ImageWritingConfig !== true) {
            $this->saveOriginalImage($destination, $img);
        }

        $config ??= ImageWritingConfig::default();

        $img->scale(width: $config->maxWidth);

        if ($config->blur) {
            $img->blur($config->blur);
        }

        $img->save($destination, $config->quality, $this->extension);
    }

    /**
     * Very hacky. But with this version I do not have to change a lot of existing code,
     * so future merges with origin will be easier.
     * Save the original version of the image for future updates in the highes possible
     * resolution. Still re-encode for security
     */
    private function saveOriginalImage(string $destination, ImageInterface $img): void
    {
        $origin = $img->origin();

        $originalExtension = $origin->fileExtension() ? FileExtension::tryCreate($origin->fileExtension()) : null;

        if ($originalExtension === null) {
            $originalExtension = FileExtension::tryCreate($origin->mediaType());
        }

        $fileExtension = $originalExtension->value ?? $this->extension->value;

        $originalImagePath = image_storage_path(sprintf(
            '%s_org.%s',
            Str::beforeLast(basename($destination), '.'),
            $fileExtension,
        ));

        if (File::exists($originalImagePath) !== true) {
            $img->save($originalImagePath, quality: 90);
        }
    }
}
