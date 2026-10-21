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
