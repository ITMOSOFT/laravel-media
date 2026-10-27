<?php

declare(strict_types=1);

namespace ItmoSoft\Media\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ItmoSoft\Media\Models\Media;
use ItmoSoft\Media\PendingMedia;
use Throwable;

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
        static::deleted(function ($model): void {
            if (! config('media.delete_files_on_model_delete', true)) {
                return;
            }

            // Run only after the parent row was successfully deleted/soft-deleted.
            // Media file cleanup itself is deferred until the transaction commits.
            try {
                DB::transaction(function () use ($model): void {
                    $model->media()->get()->each(
                        fn (Media $media) => $media->delete()
                    );
                });
            } catch (Throwable $exception) {
                // The parent row has already been deleted. Keep remaining media
                // records/files intact and make the incomplete cleanup observable.
                Log::warning('Failed to remove all media records after parent model deletion.', [
                    'model_type' => $model::class,
                    'model_id' => $model->getKey(),
                    'exception' => $exception->getMessage(),
                ]);
            }
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

        if (count(array_unique(array_map('strval', $mediaIds))) !== count($mediaIds)) {
            throw new \InvalidArgumentException(
                'Duplicate media IDs are not allowed.'
            );
        }

        DB::transaction(function () use ($mediaIds, $collection): void {
            $media = $this->media()
                ->where('collection_name', $collection)
                ->orderBy('order_column')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($mediaIds as $mediaId) {
                if (! $media->has($mediaId)) {
                    throw new \InvalidArgumentException(
                        'One or more media IDs do not belong to this model and collection.'
                    );
                }
            }

            // Partial reorder is supported: supplied IDs move to the front in
            // the requested order; omitted records retain their relative order
            // and follow the supplied IDs. Normalize every position to avoid ties.
            $requestedIds = array_map('strval', $mediaIds);
            $orderedMedia = collect($mediaIds)
                ->map(fn ($mediaId) => $media->get($mediaId));

            $omittedMedia = $media->reject(
                fn (Media $item): bool => in_array(
                    (string) $item->getKey(),
                    $requestedIds,
                    true
                )
            );

            foreach ($orderedMedia->concat($omittedMedia)->values() as $index => $item) {
                $item->update([
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
