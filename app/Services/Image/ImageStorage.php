<?php

namespace App\Services\Image;

use App\Enums\ImageChangeType;
use App\Helpers\Ulid;
use App\Values\ImageChange;
use App\Values\ImageWritingConfig;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageStorage
{
    public const string DISK = 'images';

    private const int ORIGINAL_MAX_WIDTH = 16_384;

    public function __construct(
        private readonly ImageWriter $imageWriter,
        private readonly SvgSanitizer $svgSanitizer,
    ) {}

    /**
     * Store an image and return its file name.
     *
     * @param mixed $source Any kind of image data that Intervention can read.
     * @param ?string $fileName The name to store the image under. Randomly generated if not provided.
     */
    public function storeImage(mixed $source, ?ImageWritingConfig $config = null, ?string $fileName = null): string
    {
        preg_match('/^data:(image\/[A-Za-z0-9+\-.]+);base64,/', $source, $matches);

        if (($matches[1] ?? null) === 'image/svg+xml') {
            return $this->storeSvg($source, $fileName);
        }

        $fileName ??= self::generateRandomFileName($this->imageWriter->format());
        $this->put($fileName, $this->imageWriter->encode($source, $config));

        if ($config === null) {
            rescue(fn () => $this->storeOriginalImage($source, $fileName));
        }

        return $fileName;
    }

    /**
     * Keep a re-encoded, full-resolution copy next to every primary image,
     * so that larger renditions can be generated from it later.
     */
    private function storeOriginalImage(mixed $source, string $fileName): void
    {
        $originalFileName = sprintf('%s_org.%s', Str::beforeLast($fileName, '.'), $this->imageWriter->format());

        if ($this->exists($originalFileName)) {
            return;
        }

        $originalConfig = ImageWritingConfig::make(quality: 90, maxWidth: self::ORIGINAL_MAX_WIDTH);
        $this->put($originalFileName, $this->imageWriter->encode($source, $originalConfig));
    }

    /**
     * Turn an optional image from a request into a change: null keeps the current image,
     * an empty string removes it, and anything else is stored as the replacement.
     * If the image can't be stored, the current one is kept.
     */
    public function storeOptionalImage(?string $source): ImageChange
    {
        if ($source === null) {
            return ImageChange::make(ImageChangeType::KEEP);
        }

        if ($source === '') {
            return ImageChange::make(ImageChangeType::REMOVE);
        }

        $fileName = rescue(fn () => $this->storeImage($source));

        return $fileName
            ? ImageChange::make(ImageChangeType::REPLACE, $fileName)
            : ImageChange::make(ImageChangeType::KEEP);
    }

    public function exists(?string $fileName): bool
    {
        return (bool) $fileName && self::disk()->exists($fileName);
    }

    public function get(?string $fileName): ?string
    {
        return $this->exists($fileName) ? self::disk()->get($fileName) : null;
    }

    /** @param array<string|null>|string|null $fileNames */
    public function delete(array|string|null $fileNames): void
    {
        $existing = collect(Arr::wrap($fileNames))
            ->filter()
            ->filter($this->exists(...))
            ->all();

        if (!$existing) {
            return;
        }

        throw_if(
            self::disk()->delete($existing) === false,
            RuntimeException::class,
            'Failed to delete ' . implode(', ', $existing),
        );
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::DISK);
    }

    private function storeSvg(string $source, ?string $fileName): string
    {
        $raw = base64_decode(preg_replace('/^data:image\/svg\+xml;base64,/', '', $source), true);

        throw_if($raw === false, RuntimeException::class, 'Failed to decode base64 SVG data.');

        $sanitized = $this->svgSanitizer->sanitize($raw);

        throw_if(!$sanitized, RuntimeException::class, 'Invalid SVG file.');

        $fileName ??= self::generateRandomFileName('svg');
        $this->put($fileName, $sanitized);

        return $fileName;
    }

    private function put(string $fileName, string $contents): void
    {
        throw_if(
            self::disk()->put($fileName, $contents) === false,
            RuntimeException::class,
            "Failed to store image $fileName",
        );
    }

    private static function generateRandomFileName(string $extension): string
    {
        return sprintf('%s.%s', Ulid::generate(), $extension);
    }
}
