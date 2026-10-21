<?php

declare(strict_types=1);

namespace ItmoSoft\Media\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use ItmoSoft\Media\Models\Media;
use ItmoSoft\Media\PendingMedia;

trait HasMedia
{
    public function media(): MorphMany
    {
        return $this->morphMany(
            Media::class,
            'model'
        )->orderBy('order_column')->orderBy('id');
    }

    protected static function bootHasMedia(): void
    {
        static::deleting(function ($model): void {
            if (! config('media.delete_files_on_model_delete', true)) {
                return;
            }

            DB::transaction(function () use ($model): void {
                $model->media()->get()->each(
                    fn (Media $media) => $media->delete()
                );
            });
        });
    }

    public function replaceMedia(
        UploadedFile $file,
        string $collection = 'default'
    ): Media {
        if (trim($collection) === '') {
            throw new \InvalidArgumentException(
                'The media collection name cannot be empty.'
            );
        }

        $newMedia = $this->addMedia($file)->toCollection($collection);

        $this->getMedia($collection)
            ->reject(fn (Media $media): bool => $media->is($newMedia))
            ->each(fn (Media $media) => $media->delete());

        return $newMedia;
    }

    public function reorderMedia(
        array $mediaIds,
        string $collection = 'default'
    ): void {
        if (trim($collection) === '') {
            throw new \InvalidArgumentException(
                'The media collection name cannot be empty.'
            );
        }

        if (count($mediaIds) !== count(array_unique($mediaIds))) {
            throw new \InvalidArgumentException(
                'Duplicate media IDs are not allowed.'
            );
        }

        DB::transaction(function () use ($mediaIds, $collection): void {
            $media = $this->media()
                ->where('collection_name', $collection)
                ->whereIn('id', $mediaIds)
                ->get()
                ->keyBy('id');

            if ($media->count() !== count($mediaIds)) {
                throw new \InvalidArgumentException(
                    'One or more media IDs do not belong to this model and collection.'
                );
            }

            foreach (array_values($mediaIds) as $index => $mediaId) {
                $media->get($mediaId)->update([
                    'order_column' => $index + 1,
                ]);
            }
        });
    }

    public function addMedia(UploadedFile $file): PendingMedia
    {
        return new PendingMedia($this, $file);
    }

    public function getMedia(?string $collection = null)
    {
        $query = $this->media();

        if ($collection !== null) {
            $query->where('collection_name', $collection);
        }

        return $query->get();
    }

    public function getFirstMedia(?string $collection = null): ?Media
    {
        $query = $this->media();

        if ($collection !== null) {
            $query->where('collection_name', $collection);
        }

        return $query->first();
    }

    public function getFirstMediaUrl(
        ?string $collection = null
    ): ?string {
        return $this->getFirstMedia($collection)?->getUrl();
    }
}
