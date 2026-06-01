<?php

namespace App\Observers;

use App\Facades\Dispatcher;
use App\Jobs\GenerateAlbumThumbnailJob;
use App\Models\Album;
use App\Services\Image\ModelImageObserver;
use Illuminate\Support\Facades\File;

class AlbumObserver
{
    private ModelImageObserver $coverObserver;

    public function __construct()
    {
        $this->coverObserver = ModelImageObserver::make(fieldName: 'cover', hasThumbnail: true);
    }

    public function saved(Album $album): void
    {
        if ($album->cover && !File::exists(image_storage_path($album->thumbnail))) {
            Dispatcher::dispatch(new GenerateAlbumThumbnailJob($album));
        }
    }

    public function updating(Album $album): void
    {
        if (!$album->isDirty('cover')) {
            return;
        }

        $oldCover = $album->getRawOriginal('cover');

        // If the cover is being updated, delete the old cover and thumbnail files
        rescue_if($oldCover, static function () use ($oldCover): void {
            $oldCoverPath = image_storage_path($oldCover);
            $parts = pathinfo($oldCoverPath);
            $oldFullScreenCover = sprintf('%s_fullscreen.%s', $parts['filename'], $parts['extension']);
            File::delete([
                image_storage_path($oldFullScreenCover),
            ]);
        });

        $this->coverObserver->onModelUpdating($album);
    }

    public function updated(Album $album): void
    {
        $changes = $album->getChanges();

        if (array_key_exists('name', $changes)) {
            // Keep the artist name in sync across songs and albums, but only if it actually changed.
            $album->songs()->update(['album_name' => $changes['name']]);
        }
    }

    public function deleted(Album $album): void
    {
        $this->coverObserver->onModelDeleted($album);

        $fullScreenCover = image_storage_path($album->full_screen_cover);

        rescue_if($fullScreenCover, static fn () => File::delete([
            $fullScreenCover,
        ]));
    }
}
